<?php

namespace App\Filament\Resources\Places\Pages;

use App\Filament\Resources\Places\PlaceResource;
use App\Jobs\ImportPlacesFromCsv;
use App\Models\Place;
use App\Models\PlaceImport;
use App\Models\User;
use App\Support\PlaceCsv;
use App\Support\PlaceCsvException;
use App\Support\PlaceCsvImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * استيراد أماكن من CSV (A10، 02): قالب ← رفع ← معاينة (كل سطر سليم أو غلط أو مكرر محتمل والغلط مكتوب جنبه) ← تأكيد
 * ← الاستيراد في الـ queue كمسودات ← النتيجة وملف الأخطاء.
 *
 * `?import={id}` هو رقم الرفعة اللي بتتعرض (معاينة أو نتيجة). الرفعة نفسها (فيها لحد 500 سطر) **مش**
 * property عام: Livewire كان هيحطها في كل طلب، فبتتحمّل من الداتابيز مرة في الطلب (`importRecord`).
 */
class ImportPlaces extends Page
{
    protected static string $resource = PlaceResource::class;

    protected static ?string $title = 'استيراد أماكن من CSV';

    protected string $view = 'filament.places.import';

    public const ROWS_PER_PAGE = 25;

    #[Url(as: 'import')]
    public ?int $importId = null;

    /** all | valid | duplicate | error */
    public string $filter = 'all';

    public int $rowsPage = 1;

    private ?PlaceImport $loaded = null;

    public function mount(): void
    {
        abort_unless($this->user()?->can('create', Place::class), 403);

        // الرفعة بتاعة حد تاني بتتفتح للأدمن بس
        if ($this->importId !== null) {
            $import = $this->importRecord();

            abort_if($import === null, 404);
            abort_unless($import->user_id === $this->user()?->id || $this->user()?->hasRole('admin'), 403);
        }
    }

    public function importRecord(): ?PlaceImport
    {
        if ($this->importId === null) {
            return null;
        }

        return $this->loaded ??= PlaceImport::query()->find($this->importId);
    }

    public function updatedFilter(): void
    {
        $this->rowsPage = 1;
    }

    /**
     * السطور المعروضة (الفلتر والصفحة) وعددها بعد الفلتر.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int}
     */
    public function visibleRows(): array
    {
        $import = $this->importRecord();

        if ($import === null) {
            return ['rows' => [], 'total' => 0, 'pages' => 1];
        }

        $rows = array_values(array_filter(
            $import->rows,
            fn (array $row) => $this->filter === 'all' || $row['status'] === $this->filter,
        ));

        $pages = max(1, (int) ceil(count($rows) / self::ROWS_PER_PAGE));
        $page = min(max(1, $this->rowsPage), $pages);

        return [
            'rows' => array_slice($rows, ($page - 1) * self::ROWS_PER_PAGE, self::ROWS_PER_PAGE),
            'total' => count($rows),
            'pages' => $pages,
        ];
    }

    public function previousRowsPage(): void
    {
        $this->rowsPage = max(1, $this->rowsPage - 1);
    }

    public function nextRowsPage(): void
    {
        $this->rowsPage++;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->confirmAction(),
            $this->downloadErrorsAction(),
            $this->discardAction(),
            $this->uploadAction(),
            $this->templateAction(),
            Action::make('back')
                ->label('قايمة الأماكن')
                ->icon(Heroicon::OutlinedArrowRight)
                ->color('gray')
                ->url(fn () => PlaceResource::getUrl()),
        ];
    }

    private function uploadAction(): Action
    {
        return Action::make('upload')
            ->label(fn () => $this->importId === null ? 'رفع ملف CSV' : 'رفع ملف تاني')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn () => $this->importRecord() === null || ! $this->importRecord()->isImporting())
            ->modalHeading('رفع ملف CSV')
            ->modalDescription('الملف بيتراجع الأول وبعدين تأكّد. لحد '.PlaceCsv::MAX_ROWS.' سطر.')
            ->modalSubmitActionLabel('معاينة')
            ->schema([
                FileUpload::make('file')
                    ->label('ملف CSV')
                    ->required()
                    ->storeFiles(false)
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'text/x-csv'])
                    ->maxSize((int) (PlaceCsv::MAX_BYTES / 1024))
                    ->helperText('احفظ الشيت من Excel بصيغة «CSV UTF-8». الصور بتتضاف يدوياً بعد الاستيراد.'),
            ])
            ->action(function (array $data) {
                $file = $data['file'] ?? null;

                if (is_array($file)) {
                    $file = reset($file);
                }

                if (! is_object($file) || ! method_exists($file, 'get')) {
                    Notification::make()->danger()->title('ما اتحمّلش ملف')->send();

                    return;
                }

                try {
                    $import = app(PlaceCsvImporter::class)->preview(
                        (string) $file->get(),
                        (string) $file->getClientOriginalName(),
                        $this->user(),
                    );
                } catch (PlaceCsvException $e) {
                    Notification::make()->danger()->title('الملف مش صالح')->body($e->getMessage())->persistent()->send();

                    return;
                }

                $this->redirect(PlaceResource::getUrl('import', ['import' => $import->id]));
            });
    }

    private function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label(fn () => 'تأكيد وإضافة كمسودات')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(fn () => $this->importRecord()?->isPreview() === true
                && $this->importRecord()->importableCount(true) > 0)
            ->modalHeading('إضافة الأماكن كمسودات')
            ->modalDescription(function () {
                $import = $this->importRecord();

                return $import === null ? '' : "السليم ({$import->valid_rows}) هيتضاف كمسودات. الغلط ({$import->error_rows}) مش هيتضاف وهينزل في ملف الأخطاء.";
            })
            ->modalSubmitActionLabel('إضافة')
            ->schema(fn () => ($this->importRecord()?->duplicate_rows ?? 0) > 0
                ? [
                    Toggle::make('include_duplicates')
                        ->label("استورد المكرر المحتمل كمان ({$this->importRecord()->duplicate_rows})")
                        ->helperText('بشكل افتراضي بيتخطّى ويظهر في ملف الأخطاء. فعّله لو متأكد إنهم أماكن مختلفة (فروع مثلاً).')
                        ->default(false),
                ]
                : [])
            ->action(function (array $data) {
                $import = $this->importRecord();

                if ($import === null || ! $import->isPreview()) {
                    return;
                }

                $this->authorizeImport($import);

                $include = (bool) ($data['include_duplicates'] ?? false);

                if ($import->importableCount($include) === 0) {
                    Notification::make()->warning()->title('مفيش سطور سليمة تتضاف')->send();

                    return;
                }

                // الحالة بتتغيّر بـ UPDATE شرطي: ضغطتين على «إضافة» مش بيشغّلوا الـ job مرتين
                $claimed = PlaceImport::query()
                    ->whereKey($import->id)
                    ->where('status', PlaceImport::STATUS_PREVIEWED)
                    ->update([
                        'status' => PlaceImport::STATUS_IMPORTING,
                        'include_duplicates' => $include,
                        'started_at' => now(),
                    ]);

                if ($claimed === 0) {
                    return;
                }

                ImportPlacesFromCsv::dispatch($import->id);

                $this->loaded = null;

                Notification::make()->success()->title('بدأ الاستيراد')->body('هيخلص في دقيقة أو اتنين، والنتيجة بتظهر هنا وفي الجرس.')->send();
            });
    }

    private function downloadErrorsAction(): Action
    {
        return Action::make('downloadErrors')
            ->label('تحميل ملف الأخطاء')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(function () {
                $import = $this->importRecord();

                return $import !== null && ! $import->isImporting()
                    && ($import->error_rows > 0 || ($import->duplicate_rows > 0 && ! $import->include_duplicates)
                        || $import->total_rows > $import->imported_rows && $import->isFinished());
            })
            ->action(function (): ?StreamedResponse {
                $import = $this->importRecord();

                if ($import === null) {
                    return null;
                }

                $this->authorizeImport($import);

                return response()->streamDownload(
                    fn () => print PlaceCsvImporter::errorsCsv($import),
                    'places-import-'.$import->id.'-errors.csv',
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                );
            });
    }

    private function discardAction(): Action
    {
        return Action::make('discard')
            ->label('إلغاء الاستيراد')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('إلغاء الاستيراد')
            ->modalDescription('الملف ده مش هيتضاف منه حاجة.')
            ->visible(fn () => $this->importRecord()?->isPreview() === true)
            ->action(function () {
                $import = $this->importRecord();

                if ($import === null || ! $import->isPreview()) {
                    return;
                }

                $this->authorizeImport($import);
                $import->delete();

                $this->redirect(PlaceResource::getUrl('import'));
            });
    }

    private function templateAction(): Action
    {
        return Action::make('template')
            ->label('تحميل القالب')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->visible(fn () => $this->importRecord() === null)
            ->action(fn (): StreamedResponse => response()->streamDownload(
                fn () => print PlaceCsv::template(),
                'places-template.csv',
                ['Content-Type' => 'text/csv; charset=UTF-8'],
            ));
    }

    private function authorizeImport(PlaceImport $import): void
    {
        abort_unless($import->user_id === $this->user()?->id || $this->user()?->hasRole('admin'), 403);
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
