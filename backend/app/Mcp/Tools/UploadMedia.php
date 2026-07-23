<?php

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class UploadMedia extends ViewsMaxTool
{
    /** Mime => [media type, file extension]. */
    private const SUPPORTED_MIMES = [
        'image/jpeg' => ['image', 'jpg'],
        'image/png' => ['image', 'png'],
        'image/webp' => ['image', 'webp'],
        'image/gif' => ['image', 'gif'],
        'video/mp4' => ['video', 'mp4'],
        'video/quicktime' => ['video', 'mov'],
    ];

    public function name(): string
    {
        return 'upload_media';
    }

    public function description(): string
    {
        return 'Download a file from a URL and host it on ViewsMax storage for use in '
            . 'posts. Returns a media entry ({type, url, path}) to pass to create_post. '
            . 'Supports jpeg/png/webp/gif images and mp4/mov video.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('url')->description('Publicly reachable https URL of the image or video.');
    }

    public function handle(array $arguments): ToolResult
    {
        if ($limited = $this->hourlyLimit('upload_media')) {
            return $limited;
        }

        $validated = Validator::validate($arguments, ['url' => 'required|url']);

        $maxBytes = ((int) config('mcp.upload_max_mb', 100)) * 1024 * 1024;

        $response = Http::timeout(120)->withOptions(['stream' => false])->get($validated['url']);

        if (! $response->successful()) {
            return ToolResult::error("Could not download the file (HTTP {$response->status()}).");
        }

        $mime = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if (! isset(self::SUPPORTED_MIMES[$mime])) {
            return ToolResult::error(
                "Unsupported content type '{$mime}'. Supported: " . implode(', ', array_keys(self::SUPPORTED_MIMES)) . '.'
            );
        }

        $body = $response->body();
        if (strlen($body) > $maxBytes) {
            return ToolResult::error('File exceeds the ' . config('mcp.upload_max_mb', 100) . 'MB upload limit.');
        }

        [$type, $extension] = self::SUPPORTED_MIMES[$mime];

        // Same disk + directory layout as PostMediaController uploads.
        $disk = config('filesystems.media_disk') ?: config('filesystems.default');
        $path = sprintf('posts/%d/%s.%s', $this->user()->id, Str::uuid(), $extension);
        Storage::disk($disk)->put($path, $body);

        $url = Storage::disk($disk)->url($path);
        if (! preg_match('#^https?://#i', $url)) {
            $url = url($url);
        }

        return ToolResult::json([
            'type' => $type,
            'url' => $url,
            'path' => $path,
            'disk' => $disk,
        ]);
    }
}
