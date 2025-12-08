<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Shift;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    public function loginForm()
    {
        return view('admin.auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::guard('admin')->attempt($credentials)) {
            $request->session()->regenerate();
            return redirect()->route('admin.dashboard');
        }

        return back()->withErrors([
            'email' => 'Invalid credentials.',
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/admin/login');
    }

    public function dashboard()
    {
        $totalWorkers = Worker::count();
        $activeWorkers = Worker::where('status', 'active')->count();
        
        $today = now()->toDateString();
        $presentToday = Attendance::whereDate('timestamp', $today)
            ->where('type', 'check_in')
            ->distinct('worker_id')
            ->count();
        
        $pendingLeaves = Leave::where('status', 'pending')->count();
        
        // Today's attendance
        $todayAttendance = Attendance::with('worker')
            ->whereDate('timestamp', $today)
            ->orderBy('timestamp', 'desc')
            ->limit(10)
            ->get()
            ->groupBy('worker_id')
            ->map(function ($records) {
                $worker = $records->first()->worker;
                $checkIn = $records->where('type', 'check_in')->first();
                $checkOut = $records->where('type', 'check_out')->first();
                
                return [
                    'name' => $worker->full_name,
                    'department' => $worker->department,
                    'check_in' => $checkIn ? $checkIn->timestamp->format('H:i') : null,
                    'check_out' => $checkOut ? $checkOut->timestamp->format('H:i') : null,
                    'status' => $checkIn ? 'present' : 'absent'
                ];
            });

        return view('admin.dashboard', compact(
            'totalWorkers',
            'activeWorkers',
            'presentToday',
            'pendingLeaves',
            'todayAttendance'
        ));
    }

    public function workers(Request $request)
    {
        $query = Worker::query();
        
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        $workers = $query->orderBy('full_name')->paginate(20);
        
        return view('admin.workers.index', compact('workers'));
    }

    public function showWorker($id)
    {
        $worker = Worker::with(['attendance', 'leaves', 'shifts'])->findOrFail($id);
        
        // Get attendance summary
        $attendanceSummary = Attendance::select(
                DB::raw('DATE(timestamp) as date'),
                DB::raw('MIN(CASE WHEN type = "check_in" THEN TIME(timestamp) END) as check_in'),
                DB::raw('MAX(CASE WHEN type = "check_out" THEN TIME(timestamp) END) as check_out')
            )
            ->where('worker_id', $id)
            ->groupBy(DB::raw('DATE(timestamp)'))
            ->orderBy('date', 'desc')
            ->paginate(30);
        
        // Calculate total hours worked this month
        $monthHours = Attendance::select(
                DB::raw('SUM(TIMESTAMPDIFF(HOUR, 
                    MIN(CASE WHEN type = "check_in" THEN timestamp END),
                    MAX(CASE WHEN type = "check_out" THEN timestamp END)
                )) as total_hours')
            )
            ->where('worker_id', $id)
            ->whereMonth('timestamp', now()->month)
            ->whereYear('timestamp', now()->year)
            ->first();
        
        return view('admin.workers.show', compact('worker', 'attendanceSummary', 'monthHours'));
    }

    public function createWorker()
    {
        return view('admin.workers.create');
    }

    public function storeWorker(Request $request)
    {
        $validated = $request->validate([
            'employee_code' => 'required|unique:workers|max:50',
            'full_name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:workers',
            'phone' => 'nullable|string|max:20',
            'department' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:100',
            'hire_date' => 'nullable|date',
            'pin_code' => 'nullable|string|size:4',
            'status' => 'required|in:active,inactive,suspended',
        ]);

        $worker = Worker::create($validated);
        
        return redirect()->route('admin.workers.show', $worker->id)
            ->with('success', 'Worker created successfully');
    }

    public function editWorker($id)
    {
        $worker = Worker::findOrFail($id);
        return view('admin.workers.edit', compact('worker'));
    }

    public function updateWorker(Request $request, $id)
    {
        $worker = Worker::findOrFail($id);
        
        $validated = $request->validate([
            'employee_code' => 'required|max:50|unique:workers,employee_code,' . $id,
            'full_name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:workers,email,' . $id,
            'phone' => 'nullable|string|max:20',
            'department' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:100',
            'hire_date' => 'nullable|date',
            'pin_code' => 'nullable|string|size:4',
            'status' => 'required|in:active,inactive,suspended',
        ]);

        $worker->update($validated);
        
        return redirect()->route('admin.workers.show', $worker->id)
            ->with('success', 'Worker updated successfully');
    }

    public function deleteWorker($id)
    {
        $worker = Worker::findOrFail($id);
        $worker->delete();
        
        return redirect()->route('admin.workers')
            ->with('success', 'Worker deleted successfully');
    }

    public function attendanceReport(Request $request)
    {
        $date = $request->get('date', now()->toDateString());
        
        $attendance = Attendance::with('worker')
            ->whereDate('timestamp', $date)
            ->orderBy('timestamp')
            ->get()
            ->groupBy('worker_id')
            ->map(function ($records) {
                $worker = $records->first()->worker;
                $checkIn = $records->where('type', 'check_in')->first();
                $checkOut = $records->where('type', 'check_out')->first();
                
                return [
                    'worker' => $worker,
                    'check_in' => $checkIn ? $checkIn->timestamp->format('H:i:s') : null,
                    'check_out' => $checkOut ? $checkOut->timestamp->format('H:i:s') : null,
                    'method' => $checkIn ? $checkIn->method : null,
                ];
            });
        
        return view('admin.reports.attendance', compact('attendance', 'date'));
    }

    public function monthlyReport(Request $request)
    {
        $month = $request->get('month', now()->format('Y-m'));
        
        $report = Worker::with(['attendance' => function($query) use ($month) {
            $query->whereYear('timestamp', substr($month, 0, 4))
                  ->whereMonth('timestamp', substr($month, 5, 2));
        }])->get()->map(function($worker) use ($month) {
            $days = [];
            $currentDate = \Carbon\Carbon::parse($month . '-01');
            $endDate = $currentDate->copy()->endOfMonth();
            
            while ($currentDate <= $endDate) {
                $dateStr = $currentDate->toDateString();
                $dayAttendance = $worker->attendance->where('timestamp', '>=', $dateStr . ' 00:00:00')
                    ->where('timestamp', '<=', $dateStr . ' 23:59:59');
                
                $checkIn = $dayAttendance->where('type', 'check_in')->first();
                $checkOut = $dayAttendance->where('type', 'check_out')->first();
                
                $days[$dateStr] = [
                    'check_in' => $checkIn ? $checkIn->timestamp->format('H:i') : null,
                    'check_out' => $checkOut ? $checkOut->timestamp->format('H:i') : null,
                    'present' => $checkIn ? true : false
                ];
                
                $currentDate->addDay();
            }
            
            return [
                'worker' => $worker,
                'days' => $days,
                'present_days' => collect($days)->where('present', true)->count(),
                'absent_days' => collect($days)->where('present', false)->count()
            ];
        });
        
        return view('admin.reports.monthly', compact('report', 'month'));
    }

    public function leaves()
    {
        $leaves = Leave::with('worker')
            ->orderBy('created_at', 'desc')
            ->paginate(20);
        
        return view('admin.leaves.index', compact('leaves'));
    }

    public function showLeave($id)
    {
        $leave = Leave::with('worker')->findOrFail($id);
        return view('admin.leaves.show', compact('leave'));
    }

    public function approveLeave($id)
    {
        $leave = Leave::findOrFail($id);
        $leave->update(['status' => 'approved']);
        
        return back()->with('success', 'Leave approved successfully');
    }

    public function rejectLeave(Request $request, $id)
    {
        $request->validate([
            'admin_notes' => 'required|string'
        ]);
        
        $leave = Leave::findOrFail($id);
        $leave->update([
            'status' => 'rejected',
            'admin_notes' => $request->admin_notes
        ]);
        
        return back()->with('success', 'Leave rejected');
    }

    public function shifts()
    {
        $shifts = Shift::with('worker')
            ->orderBy('shift_date', 'desc')
            ->paginate(20);
        
        return view('admin.shifts.index', compact('shifts'));
    }

    public function createShift()
    {
        $workers = Worker::where('status', 'active')->get();
        return view('admin.shifts.create', compact('workers'));
    }

    public function storeShift(Request $request)
    {
        $validated = $request->validate([
            'worker_id' => 'required|exists:workers,id',
            'shift_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'notes' => 'nullable|string'
        ]);

        Shift::create($validated);
        
        return redirect()->route('admin.shifts')
            ->with('success', 'Shift created successfully');
    }

    public function settings()
    {
        return view('admin.settings.index');
    }

    public function devices()
    {
        // Get unique devices from attendance records
        $devices = Attendance::select('device_id', DB::raw('MAX(timestamp) as last_used'))
            ->whereNotNull('device_id')
            ->groupBy('device_id')
            ->get();
        
        return view('admin.settings.devices', compact('devices'));
    }

    public function faceSettings()
    {
        return view('admin.settings.face_settings');
    }
}