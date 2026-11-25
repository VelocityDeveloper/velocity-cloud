<script setup lang="ts">
import { getLocalTimeZone, parseDate, today } from '@internationalized/date';
import type { DateValue } from '@internationalized/date';
import { CalendarDays, ChevronLeft, ChevronRight, X } from '@lucide/vue';
import {
    CalendarCell,
    CalendarCellTrigger,
    CalendarGrid,
    CalendarGridBody,
    CalendarGridHead,
    CalendarGridRow,
    CalendarHeadCell,
    CalendarHeader,
    CalendarHeading,
    CalendarNext,
    CalendarPrev,
    CalendarRoot,
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

/** Pemilih tanggal + jam bergaya Input; nilai sama dengan input datetime-local: "YYYY-MM-DDTHH:mm" atau "" (kosong). */
const props = withDefaults(
    defineProps<{ id?: string; placeholder?: string; jamAwal?: string }>(),
    {
        placeholder: 'Pilih tanggal',
        jamAwal: '00:00', // jam saat tanggal pertama kali dipilih, mis. "23:59" untuk batas akhir
    },
);
const model = defineModel<string>({ default: '' });

const dua = (n: number) => String(n).padStart(2, '0');
const daftarJam = Array.from({ length: 24 }, (_, i) => dua(i));
const daftarMenit = Array.from({ length: 60 }, (_, i) => dua(i));

const tanggal = computed(() =>
    model.value ? parseDate(model.value.slice(0, 10)) : undefined,
);
const jam = computed(() =>
    model.value ? model.value.slice(11, 13) : props.jamAwal.slice(0, 2),
);
const menit = computed(() =>
    model.value ? model.value.slice(14, 16) : props.jamAwal.slice(3, 5),
);

function atur(t: DateValue | undefined, h = jam.value, m = menit.value) {
    const d = t ?? tanggal.value ?? today(getLocalTimeZone());
    model.value = `${d.toString().slice(0, 10)}T${h}:${m}`;
}

const teks = computed(() =>
    tanggal.value
        ? `${new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(tanggal.value.toDate(getLocalTimeZone()))}, ${jam.value}.${menit.value}`
        : '',
);

const navigasi =
    'inline-flex size-7 items-center justify-center rounded-md border border-input bg-transparent opacity-70 hover:bg-accent hover:opacity-100 focus-visible:ring-[3px] focus-visible:ring-ring/50 outline-none';
</script>

<template>
    <div class="flex items-center gap-1">
        <PopoverRoot>
            <PopoverTrigger
                :id="id"
                type="button"
                :class="
                    cn(
                        'flex h-9 w-full min-w-0 items-center gap-2 rounded-md border border-input bg-transparent px-3 py-1 text-left text-base shadow-xs transition-[color,box-shadow] outline-none md:text-sm dark:bg-input/30',
                        'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                        !teks && 'text-muted-foreground',
                    )
                "
            >
                <CalendarDays
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span class="truncate">{{ teks || placeholder }}</span>
            </PopoverTrigger>
            <PopoverPortal>
                <PopoverContent
                    align="start"
                    :side-offset="4"
                    class="z-50 w-auto rounded-md border bg-popover p-3 text-popover-foreground shadow-md outline-none data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95"
                >
                    <CalendarRoot
                        v-slot="{ weekDays, grid }"
                        :model-value="tanggal"
                        locale="id-ID"
                        :week-starts-on="1"
                        fixed-weeks
                        prevent-deselect
                        @update:model-value="
                            (v) => atur(v as DateValue | undefined)
                        "
                    >
                        <CalendarHeader
                            class="flex items-center justify-between pb-2"
                        >
                            <CalendarPrev
                                :class="navigasi"
                                aria-label="Bulan sebelumnya"
                                ><ChevronLeft class="size-4"
                            /></CalendarPrev>
                            <CalendarHeading
                                class="text-sm font-medium capitalize"
                            />
                            <CalendarNext
                                :class="navigasi"
                                aria-label="Bulan berikutnya"
                                ><ChevronRight class="size-4"
                            /></CalendarNext>
                        </CalendarHeader>
                        <CalendarGrid
                            v-for="bulan in grid"
                            :key="bulan.value.toString()"
                            class="w-full border-collapse select-none"
                        >
                            <CalendarGridHead>
                                <CalendarGridRow class="flex">
                                    <CalendarHeadCell
                                        v-for="h in weekDays"
                                        :key="h"
                                        class="w-8 text-center text-[0.8rem] font-normal text-muted-foreground"
                                        >{{ h }}</CalendarHeadCell
                                    >
                                </CalendarGridRow>
                            </CalendarGridHead>
                            <CalendarGridBody>
                                <CalendarGridRow
                                    v-for="(minggu, i) in bulan.rows"
                                    :key="i"
                                    class="mt-1 flex"
                                >
                                    <CalendarCell
                                        v-for="d in minggu"
                                        :key="d.toString()"
                                        :date="d"
                                        class="p-0 text-center text-sm"
                                    >
                                        <CalendarCellTrigger
                                            :day="d"
                                            :month="bulan.value"
                                            class="inline-flex size-8 items-center justify-center rounded-md text-sm font-normal outline-none hover:bg-accent hover:text-accent-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 data-[outside-view]:text-muted-foreground data-[outside-view]:opacity-50 data-[selected]:bg-primary data-[selected]:text-primary-foreground data-[selected]:hover:bg-primary data-[today]:font-semibold data-[today]:text-brand-700"
                                        />
                                    </CalendarCell>
                                </CalendarGridRow>
                            </CalendarGridBody>
                        </CalendarGrid>
                    </CalendarRoot>
                    <div
                        class="mt-3 flex items-center gap-2 border-t pt-3 text-sm"
                    >
                        <span class="text-muted-foreground">Jam</span>
                        <Select
                            :model-value="jam"
                            @update:model-value="
                                (v) => atur(undefined, String(v))
                            "
                        >
                            <SelectTrigger
                                size="sm"
                                class="w-[4.5rem]"
                                aria-label="Jam"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent class="max-h-60">
                                <SelectItem
                                    v-for="h in daftarJam"
                                    :key="h"
                                    :value="h"
                                    >{{ h }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <span aria-hidden="true">:</span>
                        <Select
                            :model-value="menit"
                            @update:model-value="
                                (v) => atur(undefined, jam, String(v))
                            "
                        >
                            <SelectTrigger
                                size="sm"
                                class="w-[4.5rem]"
                                aria-label="Menit"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent class="max-h-60">
                                <SelectItem
                                    v-for="m in daftarMenit"
                                    :key="m"
                                    :value="m"
                                    >{{ m }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <span class="text-muted-foreground">WIB</span>
                    </div>
                </PopoverContent>
            </PopoverPortal>
        </PopoverRoot>
        <button
            v-if="model"
            type="button"
            class="inline-flex size-9 shrink-0 items-center justify-center rounded-md text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
            aria-label="Kosongkan tanggal"
            @click="model = ''"
        >
            <X class="size-4" />
        </button>
    </div>
</template>
