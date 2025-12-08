<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Worker extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_code',
        'full_name',
        'email',
        'phone',
        'department',
        'position',
        'hire_date',
        'status',
        'face_embedding',
        'face_image_path',
        'pin_code'
    ];

    protected $casts = [
        'face_embedding' => 'array',
        'hire_date' => 'date',
    ];

    public function attendance()
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaves()
    {
        return $this->hasMany(Leave::class);
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function getLatestCheckIn()
    {
        return $this->attendance()
            ->where('type', 'check_in')
            ->latest('timestamp')
            ->first();
    }

    public function getLatestCheckOut()
    {
        return $this->attendance()
            ->where('type', 'check_out')
            ->latest('timestamp')
            ->first();
    }

    public function isOnLeave($date = null)
    {
        $date = $date ?? now()->toDateString();
        
        return $this->leaves()
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->where('status', 'approved')
            ->exists();
    }

    public function getTodayAttendance()
    {
        $today = now()->toDateString();
        
        return $this->attendance()
            ->whereDate('timestamp', $today)
            ->get();
    }

    public function getMonthlyHours($year = null, $month = null)
    {
        $year = $year ?? now()->year;
        $month = $month ?? now()->month;
        
        $attendance = $this->attendance()
            ->whereYear('timestamp', $year)
            ->whereMonth('timestamp', $month)
            ->get()
            ->groupBy(function($record) {
                return $record->timestamp->toDateString();
            });
        
        $totalHours = 0;
        
        foreach ($attendance as $date => $records) {
            $checkIn = $records->where('type', 'check_in')->first();
            $checkOut = $records->where('type', 'check_out')->first();
            
            if ($checkIn && $checkOut) {
                $totalHours += $checkIn->timestamp->diffInHours($checkOut->timestamp);
            }
        }
        
        return $totalHours;
    }
}