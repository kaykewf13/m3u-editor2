<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PluginEpgRepairScanCandidate extends Model
{
    use HasFactory;

    protected $fillable = [
        'extension_plugin_run_id',
        'source_run_id',
        'channel_id',
        'playlist_id',
        'playlist_name',
        'issue',
        'decision',
        'current_epg_channel_id',
        'current_epg_channel_name',
        'current_epg_source_id',
        'current_epg_source_name',
        'suggested_epg_channel_id',
        'suggested_epg_channel_name',
        'suggested_epg_source_id',
        'suggested_epg_source_name',
        'selected_epg_source_id',
        'selected_epg_source_name',
        'source_scope',
        'confidence',
        'confidence_band',
        'match_reason',
        'repairable',
        'source_candidates',
        'apply_outcome',
        'applied',
        'review_status',
        'reviewed_by_user_id',
        'reviewed_by_user_name',
        'reviewed_at',
        'last_apply_run_id',
    ];

    protected $casts = [
        'source_candidates' => 'array',
        'repairable' => 'boolean',
        'applied' => 'boolean',
        'confidence' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ExtensionPluginRun::class, 'extension_plugin_run_id');
    }

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(ExtensionPluginRun::class, 'source_run_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
