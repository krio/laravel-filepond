<?php

namespace Sopamo\LaravelFilepond\Http\Controllers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Sopamo\LaravelFilepond\Filepond;

class FilepondController extends BaseController
{
    /**
     * Maximum size for Azure append block operations (4MB)
     */
    private const MAX_APPEND_BLOCK_SIZE = 4 * 1024 * 1024;

    /**
     * @var Filepond
     */
    private $filepond;

    public function __construct(Filepond $filepond)
    {
        $this->filepond = $filepond;
    }

    /**
     * Uploads the file to the temporary directory
     * and returns an encrypted path to the file
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function upload(Request $request)
    {
        $input = $request->file(config('filepond.input_name'));

        if ($input === null) {
            // This is a chunk initialization request
            return $this->handleChunkInitialization($request);
        }

        $file = is_array($input) ? $input[0] : $input;
        $path = config('filepond.temporary_files_path', 'filepond');
        $disk = config('filepond.temporary_files_disk', 'local');

        if (!($newFile = $file->storeAs($path . DIRECTORY_SEPARATOR . Str::random(), $file->getClientOriginalName(), $disk))) {
            return Response::make('Could not save file', 500, [
                'Content-Type' => 'text/plain',
            ]);
        }

        return Response::make($this->filepond->getServerIdFromPath($newFile), 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Check if the current storage driver is Azure Blob Storage
     *
     * @param mixed $driver The storage driver instance
     * @return bool
     */
    private function isUsingAzureDriver($driver): bool
    {
        try {
            $reflection = new \ReflectionClass($driver);
            $property = $reflection->getProperty('adapter');
            $property->setAccessible(true);
            $adapter = $property->getValue($driver);

            return $adapter instanceof \Matthewbdaly\LaravelAzureStorage\AzureBlobStorageAdapter;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get Azure client and container using reflection
     *
     * @param mixed $adapter The Azure storage adapter
     * @return array{0: ?\MicrosoftAzure\Storage\Blob\BlobRestProxy, 1: ?string}
     */
    private function getAzureClientAndContainer($adapter): array
    {
        try {
            $adapterReflection = new \ReflectionClass($adapter);

            $clientProperty = $adapterReflection->getProperty('client');
            $clientProperty->setAccessible(true);
            $client = $clientProperty->getValue($adapter);

            $containerProperty = $adapterReflection->getProperty('container');
            $containerProperty->setAccessible(true);
            $container = $containerProperty->getValue($adapter);

            return [$client, $container];
        } catch (\Exception $e) {
            return [null, null];
        }
    }

    /**
     * Create an Azure append blob
     *
     * @param \MicrosoftAzure\Storage\Blob\BlobRestProxy $client
     * @param string $container
     * @param string $fileLocation
     * @param Request $request
     * @return bool
     */
    private function createAzureAppendBlob($client, string $container, string $fileLocation, Request $request): bool
    {
        try {
            $options = new \MicrosoftAzure\Storage\Blob\Models\CreateBlobOptions();
            $options->setContentType($request->header('Content-Type', 'application/octet-stream'));

            $client->createAppendBlob(
                $container,
                $fileLocation,
                $options
            );

            return true;
        } catch (\Exception $e) {
            logger()->error('Error creating Azure append blob: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * This handles the case where filepond wants to start uploading chunks of a file
     * See: https://pqina.nl/filepond/docs/patterns/api/server/
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    private function handleChunkInitialization(Request $request)
    {
        $randomId = Str::random();
        $path = config('filepond.temporary_files_path', 'filepond');
        $disk = config('filepond.temporary_files_disk', 'local');

        $baseName = $randomId;
        if ($request->header('Upload-Name')) {
            $fileName = pathinfo($request->header('Upload-Name'), PATHINFO_FILENAME);
            $ext = pathinfo($request->header('Upload-Name'), PATHINFO_EXTENSION);
            $baseName = $fileName . '-' . $randomId . '.' . $ext;
        }
        $fileLocation = $path . DIRECTORY_SEPARATOR . $baseName;

        // Get the storage instance and check if we're using Azure
        $storage = Storage::disk($disk);
        $driver = $storage->getDriver();
        $fileCreated = false;

        if ($this->isUsingAzureDriver($driver)) {
            $reflection = new \ReflectionClass($driver);
            $property = $reflection->getProperty('adapter');
            $property->setAccessible(true);
            $adapter = $property->getValue($driver);

            [$client, $container] = $this->getAzureClientAndContainer($adapter);

            if ($client instanceof \MicrosoftAzure\Storage\Blob\BlobRestProxy && !empty($container)) {
                $fileCreated = $this->createAzureAppendBlob($client, $container, $fileLocation, $request);
            }
        }

        // Fall back to regular file creation if Azure-specific handling failed or wasn't used
        if (!$fileCreated) {
            $fileCreated = $storage->put($fileLocation, '');
        }

        if (!$fileCreated) {
            abort(500, 'Could not create file');
        }

        $filepondId = $this->filepond->getServerIdFromPath($fileLocation);

        return Response::make($filepondId, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Store a chunk using the multi-file approach (and not Azure's append blob)
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     * @throws FileNotFoundException
     */
    public function multiFileChunk(Request $request)
    {
        // Retrieve upload ID
        $encryptedPath = $request->input('patch');
        if (!$encryptedPath) {
            abort(400, 'No id given');
        }

        try {
            $finalFilePath = Crypt::decryptString($encryptedPath);
            $id = basename($finalFilePath);
        } catch (DecryptException $e) {
            abort(400, 'Invalid encryption for id');
        }

        // Retrieve disk
        $disk = config('filepond.temporary_files_disk', 'local');

        // Load chunks directory
        $basePath = config('filepond.chunks_path') . DIRECTORY_SEPARATOR . $id;

        // Get patch info
        $offset = $request->server('HTTP_UPLOAD_OFFSET');
        $length = $request->server('HTTP_UPLOAD_LENGTH');

        // Validate patch info
        if (!is_numeric($offset) || !is_numeric($length)) {
            abort(400, 'Invalid chunk length or offset');
        }

        // Store chunk
        Storage::disk($disk)
            ->put($basePath . DIRECTORY_SEPARATOR . 'patch.' . $offset, $request->getContent(), ['mimetype' => 'application/octet-stream']);
        $this->persistFileIfDone($disk, $basePath, $length, $finalFilePath);

        return Response::make('', 204);
    }

    /**
     * This checks if all chunks have been uploaded and if they have, it creates the final file
     *
     * @param $disk
     * @param $basePath
     * @param $length
     * @param $finalFilePath
     * @throws FileNotFoundException
     */
    private function persistFileIfDone($disk, $basePath, $length, $finalFilePath)
    {
        $storage = Storage::disk($disk);
        // Check total chunks size
        $size = 0;
        $chunks = $storage
            ->files($basePath);
        foreach ($chunks as $chunk) {
            $size += $storage
                ->size($chunk);
        }

        // Process finished upload
        if ($size < $length) {
            return;
        }

        // Sort chunks
        $chunks = collect($chunks);
        $chunks = $chunks->keyBy(function ($chunk) {
            return substr($chunk, strrpos($chunk, '.') + 1);
        });
        $chunks = $chunks->sortKeys();

        // Append each chunk to the final file
        $tmpFile = tmpfile();
        $tmpFileName = stream_get_meta_data($tmpFile)['uri'];
        // Append each chunk to the final file
        foreach ($chunks as $chunk) {
            // Get chunk contents
            $chunkContents = $storage->readStream($chunk);

            // Stream data from chunk to tmp file
            stream_copy_to_stream($chunkContents, $tmpFile);
        }
        $storage->put($finalFilePath, $tmpFile);
        $storage->deleteDirectory($basePath);

        if (file_exists($tmpFileName)) {
            unlink($tmpFileName);
        }
    }

    /**
     * Handle a single chunk using Azure's append blob features
     * This is the primary chunk method that uses Azure's native append blob functionality
     * for better performance with Azure storage, falling back to multiFileChunk if needed
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function chunk(Request $request)
    {
        $disk = config('filepond.temporary_files_disk', 'local');
        $storage = Storage::disk($disk);
        $driver = $storage->getDriver();

        if (!$this->isUsingAzureDriver($driver)) {
            return $this->multiFileChunk($request);
        }

        // Retrieve upload ID
        $encryptedPath = $request->input('patch');
        if (!$encryptedPath) {
            abort(400, 'No id given');
        }

        try {
            $finalFilePath = Crypt::decryptString($encryptedPath);
        } catch (DecryptException $e) {
            abort(400, 'Invalid encryption for id');
        }

        $adapter = null;
        $client = null;
        $container = null;

        // Try to get the adapter using reflection since the method might not be directly accessible
        try {
            $reflection = new \ReflectionClass($driver);
            $property = $reflection->getProperty('adapter');
            $property->setAccessible(true);
            $adapter = $property->getValue($driver);

            // Get both client and container through reflection since they're private properties
            $adapterReflection = new \ReflectionClass($adapter);

            $clientProperty = $adapterReflection->getProperty('client');
            $clientProperty->setAccessible(true);
            $client = $clientProperty->getValue($adapter);

            $containerProperty = $adapterReflection->getProperty('container');
            $containerProperty->setAccessible(true);
            $container = $containerProperty->getValue($adapter);
        } catch (\Exception $e) {
            // Fall back to regular chunk method if we can't get the adapter
            return $this->multiFileChunk($request);
        }

        if (!$client instanceof \MicrosoftAzure\Storage\Blob\BlobRestProxy) {
            return $this->multiFileChunk($request);
        }

        if (empty($container)) {
            // Fall back to regular chunk method if can't determine container
            return $this->multiFileChunk($request);
        }

        // Get patch info
        $offset = $request->server('HTTP_UPLOAD_OFFSET');
        $length = $request->server('HTTP_UPLOAD_LENGTH');

        // Validate patch info
        if (!is_numeric($offset) || !is_numeric($length)) {
            abort(400, 'Invalid chunk length or offset');
        }

        try {
            $content = $request->getContent();
            $contentLength = strlen($content);

            // Create options for append block operations
            $appendBlockOptions = new \MicrosoftAzure\Storage\Blob\Models\AppendBlockOptions();

            // If content is larger than Azure's maximum append block size, split it into smaller chunks
            if ($contentLength > self::MAX_APPEND_BLOCK_SIZE) {
                $position = 0;
                while ($position < $contentLength) {
                    $chunk = substr($content, $position, self::MAX_APPEND_BLOCK_SIZE);
                    $client->appendBlock(
                        $container,
                        $finalFilePath,
                        $chunk,
                        $appendBlockOptions
                    );
                    $position += self::MAX_APPEND_BLOCK_SIZE;
                }
            } else {
                // Content is small enough, append it directly
                $client->appendBlock(
                    $container,
                    $finalFilePath,
                    $content,
                    $appendBlockOptions
                );
            }

            return Response::make('', 204);
        } catch (\Exception $e) {
            // Fall back to regular chunk method if append blob operations fail
            return $this->multiFileChunk($request);
        }
    }

    /**
     * Takes the given encrypted filepath and deletes
     * it if it hasn't been tampered with
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function delete(Request $request)
    {
        $filePath = $this->filepond->getPathFromServerId($request->getContent());
        $folderPath = dirname($filePath);
        if (Storage::disk(config('filepond.temporary_files_disk', 'local'))->deleteDirectory($folderPath)) {
            return Response::make('', 200, [
                'Content-Type' => 'text/plain',
            ]);
        }

        return Response::make('', 500, [
            'Content-Type' => 'text/plain',
        ]);
    }
}
