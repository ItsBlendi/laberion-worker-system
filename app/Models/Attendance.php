<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'worker_id',
        'type',
        'timestamp',
        'device_id',
        'confidence',
        'method',
        'notes'
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'confidence' => 'float'
    ];

    public function worker()
    {
        return $this->belongsTo(Worker::class);
    }

    public function getFormattedTimeAttribute()
    {
        return $this->timestamp->format('H:i:s');
    }

    public function getFormattedDateAttribute()
    {
        return $this->timestamp->format('Y-m-d');
    }
}