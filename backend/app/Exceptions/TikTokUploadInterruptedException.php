<?php

namespace App\Exceptions;

/**
 * The chunk upload broke off for a reason that is NOT TikTok's answer — a
 * connection error or a media read failure — after TikTok had already
 * accepted the init. The bytes may still have arrived, so the post is not
 * failed: the job falls through to status polling and lets TikTok's own
 * verdict decide.
 */
class TikTokUploadInterruptedException extends TikTokPublishException {}
