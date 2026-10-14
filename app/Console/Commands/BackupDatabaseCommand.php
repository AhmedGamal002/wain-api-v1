<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\DatabaseBackup;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Throwable;

/**
 * نسخة احتياطية يومية لقاعدة البيانات على R2 (الـ scheduler 03:10). التفاصيل في `DatabaseBackup`.
 */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Dump the database, gzip it and upload it to the backups disk (R2), then prune old copies';

    public function handle(DatabaseBackup $backup): int
    {
        try {
            $result = $backup->run();
        } catch (Throwable $e) {
            report($e);
            $this->error('فشل النسخ الاحتياطي: '.$e->getMessage());
            $this->notifyAdmins($e);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'اتعملت نسخة: %s (%s KB)%s',
            $result['path'],
            number_format($result['bytes'] / 1024, 1),
            $result['pruned'] > 0 ? "، ومسحنا {$result['pruned']} نسخة قديمة" : '',
        ));

        return self::SUCCESS;
    }

    /**
     * فشل النسخ الاحتياطي مايفضلش في اللوج بس: الأدمنز يشوفوه في جرس اللوحة.
     */
    private function notifyAdmins(Throwable $e): void
    {
        try {
            $admins = User::query()->role('admin')->where('status', 'active')->get();

            foreach ($admins as $admin) {
                Notification::make()
                    ->danger()
                    ->title('فشل النسخ الاحتياطي اليومي لقاعدة البيانات')
                    ->body($e->getMessage())
                    ->sendToDatabase($admin);
            }
        } catch (Throwable $notificationError) {
            report($notificationError);
        }
    }
}
