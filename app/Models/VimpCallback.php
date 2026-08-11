<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VimpCallback extends Model
{
    public const TYPE_MEDIUM = 'medium';
    public const TYPE_THUMBNAIL = 'thumbnail';
    public const TYPE_SPRITEMAP = 'spritemap';
    public const TYPE_FINISHED = 'finished';
    public const TYPE_ERROR = 'error';

    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PREPARATION_FAILED = 'preparation_failed';

    protected $fillable = [
        'user_id',
        'download_id',
        'video_id',
        'mediakey',
        'type',
        'status',
        'dedupe_key',
        'payload',
        'attempts',
        'last_status_code',
        'last_response',
        'last_error',
        'next_attempt_at',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'last_status_code' => 'integer',
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function download()
    {
        return $this->belongsTo(Download::class);
    }

    public function video()
    {
        return $this->belongsTo(Video::class);
    }
}
