<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class AttendanceController extends Controller
{
    public function checkInOut(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:5120',
            'device_id' => 'required|string',
            'action' => 'nullable|in:check_in,check_out'
        ]);

        // Save uploaded image temporarily
        $imagePath = $request->file('image')->store('temp', 'public');
        $imageFullPath = storage_path('app/public/' . $imagePath);

        // Call Python face recognition service
        $result = $this->recognizeFace($imageFullPath);
        
        // Delete temp image
        Storage::disk('public')->delete($imagePath);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message']
            ], 404);
        }

        $worker = Worker::find($result['worker_id']);
        
        if (!$worker) {
            return response()->json([
                'success' => false,
                'message' => 'Worker not found'
            ], 404);
        }

        // Check if worker is active
        if ($worker->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Worker account is not active'
            ], 403);
        }

        // Determine check type
        $checkType = $request->get('action');
        if (!$checkType) {
            $checkType = $this->determineCheckType($worker->id);
        }

        // Record attendance
        $attendance = Attendance::create([
            'worker_id' => $worker->id,
            'type' => $checkType,
            'timestamp' => now(),
            'device_id' => $request->device_id,
            'confidence' => $result['confidence'],
            'method' => 'face'
        ]);

        return response()->json([
            'success' => true,
            'worker' => [
                'id' => $worker->id,
                'name' => $worker->full_name,
                'employee_code' => $worker->employee_code,
                'department' => $worker->department
            ],
            'attendance' => [
                'type' => $checkType,
                'time' => $attendance->timestamp->format('H:i:s'),
                'date' => $attendance->timestamp->format('Y-m-d')
            ]
        ]);
    }

    public function enrollWorker(Request $request)
    {
        $request->validate([
            'worker_id' => 'required|exists:workers,id',
            'images' => 'required|array|min:3|max:10',
            'images.*' => 'image|max:5120'
        ]);

        $worker = Worker::find($request->worker_id);
        
        if (!$worker) {
            return response()->json([
                'success' => false,
                'message' => 'Worker not found'
            ], 404);
        }

        $responses = [];
        
        foreach ($request->file('images') as $image) {
            // Save image temporarily
            $tempPath = $image->store('temp', 'public');
            $tempFullPath = storage_path('app/public/' . $tempPath);
            
            // Send to Python service for enrollment
            $response = $this->enrollFace($tempFullPath, $worker->id);
            $responses[] = $response;
            
            // Delete temp image
            Storage::disk('public')->delete($tempPath);
            
            if (!$response['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to enroll face: ' . $response['message']
                ], 400);
            }
        }

        // Save first image path
        $firstImage = $request->file('images')[0];
        $imagePath = $firstImage->store('worker_faces/' . $worker->id, 'public');
        
        $worker->update([
            'face_image_path' => $imagePath
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Face enrolled successfully',
            'worker' => $worker,
            'images_processed' => count($responses)
        ]);
    }

    public function manualCheck(Request $request)
    {
        $request->validate([
            'employee_code' => 'required|exists:workers,employee_code',
            'pin_code' => 'required|string|size:4',
            'device_id' => 'required|string',
            'action' => 'required|in:check_in,check_out'
        ]);

        $worker = Worker::where('employee_code', $request->employee_code)->first();

        if (!$worker) {
            return response()->json([
                'success' => false,
                'message' => 'Worker not found'
            ], 404);
        }

        if ($worker->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Worker account is not active'
            ], 403);
        }

        if ($worker->pin_code !== $request->pin_code) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid PIN code'
            ], 401);
        }

        $attendance = Attendance::create([
            'worker_id' => $worker->id,
            'type' => $request->action,
            'timestamp' => now(),
            'device_id' => $request->device_id,
            'confidence' => 1.0,
            'method' => 'pin'
        ]);

        return response()->json([
            'success' => true,
            'worker' => [
                'id' => $worker->id,
                'name' => $worker->full_name,
                'employee_code' => $worker->employee_code
            ],
            'attendance' => [
                'type' => $request->action,
                'time' => $attendance->timestamp->format('H:i:s')
            ]
        ]);
    }

    public function todayAttendance()
    {
        $today = now()->toDateString();
        
        $attendance = Worker::with(['attendance' => function($query) use ($today) {
            $query->whereDate('timestamp', $today);
        }])->get()->map(function($worker) {
            $checkIn = $worker->attendance->where('type', 'check_in')->first();
            $checkOut = $worker->attendance->where('type', 'check_out')->first();
            
            $hours = null;
            if ($checkIn && $checkOut) {
                $hours = $checkIn->timestamp->diffInHours($checkOut->timestamp);
            }
            
            return [
                'id' => $worker->id,
                'name' => $worker->full_name,
                'department' => $worker->department,
                'check_in' => $checkIn ? $checkIn->timestamp->format('H:i') : null,
                'check_out' => $checkOut ? $checkOut->timestamp->format('H:i') : null,
                'hours' => $hours,
                'status' => $checkIn ? 'present' : 'absent'
            ];
        });
        
        return response()->json($attendance->values());
    }

    private function recognizeFace($imagePath)
    {
        $pythonServiceUrl = env('PYTHON_SERVICE_URL', 'http://localhost:5000');
        
        try {
            $response = Http::timeout(10)
                ->attach('image', file_get_contents($imagePath), 'face.jpg')
                ->post($pythonServiceUrl . '/recognize');
            
            if ($response->successful()) {
                return $response->json();
            }
            
            return [
                'success' => false,
                'message' => 'Face recognition service error: ' . $response->status()
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Face recognition service unavailable: ' . $e->getMessage()
            ];
        }
    }

    private function enrollFace($imagePath, $workerId)
    {
        $pythonServiceUrl = env('PYTHON_SERVICE_URL', 'http://localhost:5000');
        
        try {
            $response = Http::timeout(10)
                ->attach('image', file_get_contents($imagePath), 'enroll.jpg')
                ->post($pythonServiceUrl . '/enroll', [
                    'worker_id' => $workerId
                ]);
            
            if ($response->successful()) {
                return $response->json();
            }
            
            return [
                'success' => false,
                'message' => 'Enrollment service error: ' . $response->status()
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Enrollment service unavailable: ' . $e->getMessage()
            ];
        }
    }

    private function determineCheckType($workerId)
    {
        $lastCheck = Attendance::where('worker_id', $workerId)
            ->latest('timestamp')
            ->first();

        if (!$lastCheck || $lastCheck->type === 'check_out') {
            return 'check_in';
        }

        return 'check_out';
    }
}