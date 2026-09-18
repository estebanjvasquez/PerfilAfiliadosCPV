<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyPage extends Model
{
    protected $connection = 'pgsql';

    protected $fillable = [
        'crawl_run_id',
        'empresa_id',
        'url',
        'page_type',
        'http_status',
        'title',
        'content_hash',
        'fetched_at',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
        'http_status' => 'integer',
    ];

    public function crawlRun()
    {
        return $this->belongsTo(CompanyCrawlRun::class, 'crawl_run_id');
    }

    public function empresa()
    {
        return $this->belongsTo(EmpresaPgsql::class, 'empresa_id');
    }

    public function termMatches()
    {
        return $this->hasMany(CompanyTermMatch::class, 'page_id');
    }
}
