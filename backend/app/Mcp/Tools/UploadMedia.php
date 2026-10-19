<?php

namespace App\Mcp\Tools;

use App\Support\SafeHttp;
use App\Support\UnsafeUrlException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Upload media from a URL')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(true)]
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
        'video/x-m4v' => ['video', 'mp4'], // how libmagic reads some phone-exported mp4s
    ];

    public function name(): string
    {
        return 'upload_media';
    }

    public function description(): string
    {
        return 'Download a file from a URL and host it on ViewsMax storage for use in '
            . 'posts. Returns a media entry ({type, url, path}) to pass to create_post. '
            . 'Supports jpeg/png/webp/gif images and mp4/mov video. '
            . "Each call stores a new copy of the file, so don't repeat a call that already succeeded.";
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('url')->description('Publicly reachable https URL of the image or video.')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        if ($limited = $this->hourlyLimit('upload_media')) {
            return $limited;
        }

        $validated = Validator::validate($arguments, ['url' => 'required|url']);

        $maxBytes = ((int) config('mcp.upload_max_mb', 100)) * 1024 * 1024;

        // SafeHttp: public addresses only (no EC2 metadata / private network),
        // redirects re-checked, download aborted once it passes the cap.
        try {
            $body = SafeHttp::get($validated['url'], $maxBytes, 120)['body'];
        } catch (UnsafeUrlException $e) {
            return ToolResult::error($e->getMessage());
        }

        // Type the file by its bytes: the header can lie, and S3/R2 objects
        // stored without a type arrive as binary/octet-stream. Never echo the
        // header back — it would describe whatever the URL really pointed at.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body) ?: '';
        if (! isset(self::SUPPORTED_MIMES[$mime])) {
            return ToolResult::error('That file is not a supported image or video (jpeg, png, webp, gif, mp4, mov).');
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
