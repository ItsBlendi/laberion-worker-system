<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Worker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create admin users
        AdminUser::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@laberion.com',
            'password' => Hash::make('admin123'),
            'role' => 'super_admin',
        ]);

        AdminUser::create([
            'name' => 'Admin User',
            'email' => 'admin@laberion.com',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
        ]);

        AdminUser::create([
            'name' => 'Supervisor',
            'email' => 'supervisor@laberion.com',
            'password' => Hash::make('supervisor123'),
            'role' => 'supervisor',
        ]);

        // Create sample workers
        $departments = [
            'Production',
            'Quality Control',
            'Packaging',
            'Logistics',
            'Maintenance',
            'Administration'
        ];

        $positions = [
            'Production' => ['Machine Operator', 'Line Supervisor', 'Production Manager'],
            'Quality Control' => ['QC Inspector', 'QC Supervisor', 'Lab Technician'],
            'Packaging' => ['Packaging Operator', 'Packaging Supervisor'],
            'Logistics' => ['Warehouse Worker', 'Forklift Operator', 'Logistics Coordinator'],
            'Maintenance' => ['Maintenance Technician', 'Electrician', 'Mechanic'],
            'Administration' => ['HR Officer', 'Accountant', 'Office Assistant']
        ];

        $workerData = [];
        
        for ($i = 1; $i <= 50; $i++) {
            $department = $departments[array_rand($departments)];
            $positionOptions = $positions[$department];
            $position = $positionOptions[array_rand($positionOptions)];
            
            $workerData[] = [
                'employee_code' => 'LAB' . str_pad($i, 4, '0', STR_PAD_LEFT),
                'full_name' => $this->generateAlbanianName(),
                'email' => 'worker' . $i . '@laberion.com',
                'phone' => '+383 44 ' . str_pad(rand(100, 999), 3, '0') . ' ' . str_pad(rand(100, 999), 3, '0'),
                'department' => $department,
                'position' => $position,
                'hire_date' => now()->subDays(rand(30, 365))->format('Y-m-d'),
                'status' => rand(0, 10) > 1 ? 'active' : (rand(0, 1) ? 'inactive' : 'suspended'),
                'pin_code' => str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT),
                'notes' => rand(0, 1) ? 'Reliable worker' : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Insert workers in batches
        foreach (array_chunk($workerData, 10) as $chunk) {
            Worker::insert($chunk);
        }

        // Create sample attendance records for last 30 days
        $workers = Worker::where('status', 'active')->limit(30)->get();
        
        foreach ($workers as $worker) {
            $daysToCreate = rand(15, 25); // Each worker worked 15-25 days
            
            for ($day = 0; $day < $daysToCreate; $day++) {
                $date = now()->subDays($day);
                
                // Skip weekends (20% chance to work on weekend)
                if ($date->isWeekend() && rand(0, 100) > 20) {
                    continue;
                }
                
                // Create check-in
                $checkInTime = $date->copy()
                    ->setHour(rand(6, 8)) // Between 6 AM and 8 AM
                    ->setMinute(rand(0, 59))
                    ->setSecond(rand(0, 59));
                
                \App\Models\Attendance::create([
                    'worker_id' => $worker->id,
                    'type' => 'check_in',
                    'timestamp' => $checkInTime,
                    'device_id' => 'tablet-' . rand(1, 3),
                    'confidence' => rand(85, 99) / 100,
                    'method' => rand(0, 10) > 1 ? 'face' : 'pin',
                    'created_at' => $checkInTime,
                    'updated_at' => $checkInTime,
                ]);
                
                // Create check-out (4-10 hours later)
                $checkOutTime = $checkInTime->copy()->addHours(rand(4, 10));
                
                \App\Models\Attendance::create([
                    'worker_id' => $worker->id,
                    'type' => 'check_out',
                    'timestamp' => $checkOutTime,
                    'device_id' => 'tablet-' . rand(1, 3),
                    'confidence' => rand(85, 99) / 100,
                    'method' => rand(0, 10) > 1 ? 'face' : 'pin',
                    'created_at' => $checkOutTime,
                    'updated_at' => $checkOutTime,
                ]);
            }
        }

        // Create sample leaves
        $activeWorkers = Worker::where('status', 'active')->limit(15)->get();
        
        foreach ($activeWorkers as $worker) {
            $leaveTypes = ['vacation', 'sick', 'personal', 'other'];
            
            for ($i = 0; $i < rand(1, 3); $i++) {
                $startDate = now()->addDays(rand(5, 60));
                $duration = rand(1, 14);
                $endDate = $startDate->copy()->addDays($duration);
                
                \App\Models\Leave::create([
                    'worker_id' => $worker->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'type' => $leaveTypes[array_rand($leaveTypes)],
                    'reason' => $this->generateLeaveReason(),
                    'status' => rand(0, 10) > 2 ? 'approved' : (rand(0, 1) ? 'pending' : 'rejected'),
                    'admin_notes' => rand(0, 1) ? 'Approved as requested' : null,
                    'created_at' => now()->subDays(rand(1, 30)),
                    'updated_at' => now()->subDays(rand(0, 10)),
                ]);
            }
        }

        // Create sample shifts for next 30 days
        $allWorkers = Worker::where('status', 'active')->limit(40)->get();
        
        foreach ($allWorkers as $worker) {
            for ($day = 0; $day < 30; $day++) {
                $shiftDate = now()->addDays($day);
                
                // Skip weekends (20% chance to have shift on weekend)
                if ($shiftDate->isWeekend() && rand(0, 100) > 20) {
                    continue;
                }
                
                // 10% chance to skip this day (day off)
                if (rand(0, 100) > 90) {
                    continue;
                }
                
                $startHour = rand(6, 9); // Start between 6 AM and 9 AM
                $duration = rand(6, 10); // 6-10 hour shift
                
                \App\Models\Shift::create([
                    'worker_id' => $worker->id,
                    'shift_date' => $shiftDate,
                    'start_time' => sprintf('%02d:00:00', $startHour),
                    'end_time' => sprintf('%02d:00:00', $startHour + $duration),
                    'notes' => rand(0, 1) ? 'Regular shift' : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->command->info('Database seeded successfully!');
        $this->command->info('Admin credentials:');
        $this->command->info('Super Admin: superadmin@laberion.com / admin123');
        $this->command->info('Admin: admin@laberion.com / admin123');
        $this->command->info('Supervisor: supervisor@laberion.com / supervisor123');
    }

    /**
     * Generate Albanian names
     */
    private function generateAlbanianName(): string
    {
        $firstNames = [
            'Agim', 'Artan', 'Blerim', 'Dritan', 'Edon', 'Fisnik', 'Genc', 'Hasan', 'Ilir', 'Jetmir',
            'Kastriot', 'Lirim', 'Mentor', 'Naim', 'Osman', 'Përparim', 'Qazim', 'Rinor', 'Shkëlzen', 'Trim',
            'Valon', 'Ylli', 'Zef',
            'Arta', 'Blerina', 'Drita', 'Elona', 'Fjolla', 'Gentiana', 'Hana', 'Iliriana', 'Jehona', 'Kaltrina',
            'Liridona', 'Merita', 'Nexhare', 'Ornela', 'Pranvera', 'Qendresa', 'Rrezarta', 'Shpresa', 'Teuta',
            'Valbona', 'Yllka', 'Zana'
        ];

        $lastNames = [
            'Krasniqi', 'Gashi', 'Berisha', 'Kastrati', 'Morina', 'Kryeziu', 'Rexhepi', 'Hoxha', 'Bajrami', 'Demiri',
            'Zeka', 'Lika', 'Shatri', 'Kadriu', 'Avdullahu', 'Jashari', 'Gjergji', 'Dervishi', 'Mulliqi', 'Shehu',
            'Bajraktari', 'Thaçi', 'Veseli', 'Mehmeti', 'Musliu', 'Bytyqi', 'Dushku', 'Kurti', 'Hyseni', 'Tahiri'
        ];

        return $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
    }

    /**
     * Generate leave reasons
     */
    private function generateLeaveReason(): string
    {
        $reasons = [
            'Annual vacation leave',
            'Sick leave - doctor appointment',
            'Family emergency',
            'Personal matters to attend',
            'Medical treatment required',
            'Wedding ceremony',
            'Family event',
            'Rest and recuperation',
            'Educational purposes',
            'Religious holiday',
            'Moving to new house',
            'Child care responsibilities',
            'Medical procedure',
            'Mental health day',
            'Travel for personal reasons'
        ];

        return $reasons[array_rand($reasons)];
    }
}