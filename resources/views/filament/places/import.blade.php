@php
    use App\Filament\Resources\Places\PlaceResource;
    use App\Models\PlaceImport;
    use App\Support\PlaceCsv;

    /** @var \App\Models\PlaceImport|null $import */
    $import = $this->importRecord();
    $visible = $this->visibleRows();

    $statusLabels = [
        PlaceImport::ROW_VALID => ['سليم', 'success'],
        PlaceImport::ROW_DUPLICATE => ['مكرر محتمل', 'warning'],
        PlaceImport::ROW_ERROR => ['غلط', 'danger'],
    ];
    $filters = [
        'all' => 'الكل',
        PlaceImport::ROW_VALID => 'سليم',
        PlaceImport::ROW_DUPLICATE => 'مكرر محتمل',
        PlaceImport::ROW_ERROR => 'غلط',
    ];
@endphp

<x-filament-panels::page>
    @if ($import === null)
        <x-filament::section heading="الخطوات" description="إدخال أماكن كتير مرة واحدة من شيت Excel">
            <ol style="margin: 0; padding-inline-start: 1.25rem; display: flex; flex-direction: column; gap: .5rem;">
                <li>حمّل القالب (زرار «تحميل القالب» فوق) واكتب فيه الأماكن، سطر لكل مكان. الأعمدة بأسماء حقول فورم المكان.</li>
                <li>ارفع الملف بزرار «رفع ملف CSV»: بيتراجع الأول من غير ما يتضاف حاجة، وكل سطر عليه علامة سليم أو غلط أو مكرر محتمل والغلط مكتوب جنبه.</li>
                <li>أكّد: السليم بس بيتضاف <strong>كمسودات</strong>، والغلط بيفضل في ملف أخطاء تصلّحه وترفعه تاني.</li>
            </ol>
            <p style="margin: .75rem 0 0; font-size: .875rem; opacity: .8;">
                أقصى {{ PlaceCsv::MAX_ROWS }} سطر في الملف. الصور مش بتتستورد: بتتضاف من تاب «الصور» بعد كده.
                التصنيفات والخصائص بالـ slug أو بالاسم مفصولة بـ ; والفاصل في الملف , أو ; (Excel بالعربي بيحفظ بـ ;).
            </p>
        </x-filament::section>

        <x-filament::section heading="أعمدة القالب" collapsible>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
                    <thead>
                        <tr style="text-align: start; border-bottom: 1px solid rgba(127, 127, 127, .3);">
                            <th style="padding: .5rem; text-align: start;">العمود</th>
                            <th style="padding: .5rem; text-align: start;">إلزامي</th>
                            <th style="padding: .5rem; text-align: start;">الصيغة</th>
                            <th style="padding: .5rem; text-align: start;">مثال</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (PlaceCsv::guide() as $column)
                            <tr style="border-bottom: 1px solid rgba(127, 127, 127, .15);">
                                <td style="padding: .5rem;"><code dir="ltr">{{ $column['column'] }}</code></td>
                                <td style="padding: .5rem;">{{ $column['required'] ? 'نعم' : '' }}</td>
                                <td style="padding: .5rem;">{{ $column['format'] }}</td>
                                <td style="padding: .5rem;"><span dir="ltr">{{ $column['example'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @else
        @if ($import->isImporting())
            <div wire:poll.3s></div>
        @endif

        <x-filament::section>
            <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem;">
                <div>
                    <strong dir="auto">{{ $import->file_name }}</strong>
                    <div style="font-size: .8125rem; opacity: .7;">
                        {{ $import->created_at?->timezone('Africa/Cairo')->format('Y-m-d H:i') }}
                        @if ($import->user) · {{ $import->user->name }} @endif
                    </div>
                </div>

                <div style="display: flex; flex-wrap: wrap; gap: .5rem;">
                    <x-filament::badge color="gray">{{ $import->total_rows }} سطر</x-filament::badge>
                    <x-filament::badge color="success">{{ $import->valid_rows }} سليم</x-filament::badge>
                    @if ($import->duplicate_rows > 0)
                        <x-filament::badge color="warning">{{ $import->duplicate_rows }} مكرر محتمل</x-filament::badge>
                    @endif
                    @if ($import->error_rows > 0)
                        <x-filament::badge color="danger">{{ $import->error_rows }} غلط</x-filament::badge>
                    @endif
                </div>
            </div>

            <div style="margin-top: 1rem;">
                @if ($import->isPreview())
                    <p style="margin: 0;">
                        ده معاينة: ما اتضافش حاجة لسه.
                        @if ($import->valid_rows + $import->duplicate_rows === 0)
                            مفيش سطر سليم، صلّح الملف وارفعه تاني.
                        @else
                            راجع السطور واضغط «تأكيد وإضافة كمسودات».
                        @endif
                    </p>
                @elseif ($import->isImporting())
                    <p style="margin: 0;">
                        جاري الاستيراد في الخلفية… اتضاف {{ $import->imported_rows }} مكان لحد دلوقتي. الصفحة بتتحدّث لوحدها.
                    </p>
                @elseif ($import->status === PlaceImport::STATUS_FAILED)
                    <p style="margin: 0; color: var(--danger-600);">
                        الاستيراد وقف بخطأ. اتضاف {{ $import->imported_rows }} مكان كمسودات قبل الخطأ.
                        {{ $import->error_message }}
                    </p>
                @else
                    <p style="margin: 0;">
                        خلص: اتضاف <strong>{{ $import->imported_rows }}</strong> مكان كمسودات@if ($import->total_rows > $import->imported_rows)
                            واتعدّى {{ $import->total_rows - $import->imported_rows }} (حمّل ملف الأخطاء)@endif.
                        <a href="{{ PlaceResource::getUrl('index') }}?tab=draft" style="color: var(--primary-600); text-decoration: underline;">افتح المسودات</a>
                    </p>
                @endif

                @if (! empty($import->ignored_columns))
                    <p style="margin: .5rem 0 0; font-size: .8125rem; opacity: .75;">
                        أعمدة في الملف مش من القالب واتجاهلت: <span dir="ltr">{{ implode('، ', $import->ignored_columns) }}</span>
                    </p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section>
            <div style="display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem;">
                @foreach ($filters as $key => $label)
                    <x-filament::button
                        size="sm"
                        :color="$this->filter === $key ? 'primary' : 'gray'"
                        wire:click="$set('filter', '{{ $key }}')"
                    >{{ $label }}</x-filament::button>
                @endforeach
            </div>

            @if ($visible['rows'] === [])
                <p style="margin: 0; opacity: .7;">مفيش سطور بالفلتر ده.</p>
            @else
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
                        <thead>
                            <tr style="border-bottom: 1px solid rgba(127, 127, 127, .3);">
                                <th style="padding: .5rem; text-align: start; width: 4rem;">السطر</th>
                                <th style="padding: .5rem; text-align: start;">المكان</th>
                                <th style="padding: .5rem; text-align: start; width: 9rem;">الحالة</th>
                                <th style="padding: .5rem; text-align: start;">الملاحظات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($visible['rows'] as $row)
                                @php [$label, $color] = $statusLabels[$row['status']] ?? [$row['status'], 'gray']; @endphp
                                <tr style="border-bottom: 1px solid rgba(127, 127, 127, .15); vertical-align: top;">
                                    <td style="padding: .5rem;">{{ $row['line'] }}</td>
                                    <td style="padding: .5rem;" dir="auto">{{ $row['label'] }}</td>
                                    <td style="padding: .5rem;">
                                        <x-filament::badge :color="$color">{{ $label }}</x-filament::badge>
                                        @if (($row['result'] ?? null) === PlaceImport::RESULT_IMPORTED)
                                            <div style="margin-top: .25rem; font-size: .8125rem;">
                                                <a href="{{ PlaceResource::getUrl('view', ['record' => $row['place_id']]) }}" style="color: var(--primary-600); text-decoration: underline;">اتضاف: افتحه</a>
                                            </div>
                                        @elseif (($row['result'] ?? null) === PlaceImport::RESULT_FAILED)
                                            <div style="margin-top: .25rem; font-size: .8125rem; color: var(--danger-600);">فشل الحفظ</div>
                                        @elseif (($row['result'] ?? null) === PlaceImport::RESULT_SKIPPED)
                                            <div style="margin-top: .25rem; font-size: .8125rem; opacity: .7;">اتعدّى</div>
                                        @endif
                                    </td>
                                    <td style="padding: .5rem;">
                                        @foreach (array_merge($row['errors'] ?? [], $row['notes'] ?? [], array_filter([$row['failure'] ?? null])) as $note)
                                            <div>{{ $note }}</div>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($visible['pages'] > 1)
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 1rem;">
                        <x-filament::button size="sm" color="gray" wire:click="previousRowsPage" :disabled="$this->rowsPage <= 1">السابق</x-filament::button>
                        <span style="font-size: .875rem;">صفحة {{ min($this->rowsPage, $visible['pages']) }} من {{ $visible['pages'] }} ({{ $visible['total'] }} سطر)</span>
                        <x-filament::button size="sm" color="gray" wire:click="nextRowsPage" :disabled="$this->rowsPage >= $visible['pages']">التالي</x-filament::button>
                    </div>
                @endif
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
