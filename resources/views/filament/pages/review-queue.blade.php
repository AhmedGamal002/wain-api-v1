{{--
    طابور المراجعة (A6): الجدول + شريط اختصارات الكيبورد. الاختصارات من غير ما الماوس يتلمس:
      j / ↓ التالي · k / ↑ السابق · a نشر · r رفض · o فتح المكان
    بتفتح نفس modal الزرار (`mountTableAction`)، فالقرار يعدّي على نفس القواعد والتأكيد. بتتعطّل لما المستخدم بيكتب
    في حقل أو لما modal مفتوح.
--}}
<x-filament-panels::page>
    @php($items = method_exists($this, 'keyboardItems') ? $this->keyboardItems() : [])

    @if (count($items) > 0)
        <div
            wire:key="queue-keys-{{ md5(json_encode(array_column($items, 'id'))) }}"
            x-data="{
                items: @js($items),
                index: 0,
                get current() { return this.items[this.index] ?? null },
                move(step) {
                    if (this.items.length === 0) return
                    this.index = (this.index + step + this.items.length) % this.items.length
                },
                typing(event) {
                    const element = event.target
                    return element && (['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName) || element.isContentEditable)
                },
                busy() {
                    return Array.isArray($wire.mountedActions) && $wire.mountedActions.length > 0
                },
                onKey(event) {
                    if (event.ctrlKey || event.metaKey || event.altKey || this.typing(event) || this.busy() || !this.current) return

                    const key = event.key.toLowerCase()

                    if (key === 'j' || key === 'arrowdown') { event.preventDefault(); this.move(1) }
                    else if (key === 'k' || key === 'arrowup') { event.preventDefault(); this.move(-1) }
                    else if (key === 'a' && this.current.publish) { event.preventDefault(); $wire.mountTableAction('publish', String(this.current.id)) }
                    else if (key === 'r' && this.current.reject) { event.preventDefault(); $wire.mountTableAction('reject', String(this.current.id)) }
                    else if (key === 'o') { event.preventDefault(); window.location.href = this.current.url }
                },
            }"
            x-on:keydown.window="onKey($event)"
            class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"
        >
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <span class="font-semibold text-gray-950 dark:text-white">
                    <span x-text="index + 1"></span> / <span x-text="items.length"></span>
                    —
                    <span x-text="current ? current.name : ''"></span>
                </span>

                <span class="flex flex-wrap items-center gap-2 text-gray-500 dark:text-gray-400">
                    <span><kbd class="rounded border px-1.5">J</kbd> <kbd class="rounded border px-1.5">K</kbd> تنقّل</span>
                    <span x-show="current && current.publish"><kbd class="rounded border px-1.5">A</kbd> نشر</span>
                    <span x-show="current && current.reject"><kbd class="rounded border px-1.5">R</kbd> رفض</span>
                    <span><kbd class="rounded border px-1.5">O</kbd> فتح</span>
                </span>
            </div>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
