<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { ref, watch } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

const props = withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    { label: 'Menu' },
);

const { currentUrl, isCurrentOrParentUrl } = useCurrentUrl();

const grupAktif = (item: NavItem): boolean =>
    isCurrentOrParentUrl(item.href) ||
    !!item.items?.some((s) => isCurrentOrParentUrl(s.href));

// smart expand: hanya satu grup terbuka; grup halaman aktif terbuka otomatis saat pindah halaman
const terbuka = ref<string | null>(null);
watch(
    currentUrl,
    () => {
        const aktif = props.items.find((i) => i.items?.length && grupAktif(i));

        if (aktif) {
            terbuka.value = aktif.title;
        }
    },
    { immediate: true },
);
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>{{ label }}</SidebarGroupLabel>
        <SidebarMenu>
            <Collapsible
                v-for="item in items"
                :key="item.title"
                as-child
                :open="terbuka === item.title"
                @update:open="(v: boolean) => (terbuka = v ? item.title : null)"
            >
                <SidebarMenuItem>
                    <SidebarMenuButton
                        as-child
                        :is-active="grupAktif(item)"
                        :tooltip="item.title"
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                    <SidebarMenuBadge
                        v-if="item.badge"
                        :class="{ 'right-7': item.items?.length }"
                        class="rounded-full bg-amber-500 px-1.5 text-white peer-hover/menu-button:text-white peer-data-[active=true]/menu-button:text-white"
                        :aria-label="`${item.badge} belum dijawab`"
                        >{{
                            item.badge > 99 ? '99+' : item.badge
                        }}</SidebarMenuBadge
                    >
                    <template v-if="item.items?.length">
                        <CollapsibleTrigger as-child>
                            <SidebarMenuAction
                                class="data-[state=open]:rotate-90"
                                :aria-label="`Buka/tutup ${item.title}`"
                            >
                                <ChevronRight />
                            </SidebarMenuAction>
                        </CollapsibleTrigger>
                        <CollapsibleContent
                            class="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down"
                        >
                            <SidebarMenuSub>
                                <SidebarMenuSubItem
                                    v-for="sub in item.items"
                                    :key="sub.title"
                                >
                                    <SidebarMenuSubButton
                                        as-child
                                        :is-active="
                                            isCurrentOrParentUrl(sub.href)
                                        "
                                    >
                                        <Link :href="sub.href">
                                            <span>{{ sub.title }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </template>
                </SidebarMenuItem>
            </Collapsible>
        </SidebarMenu>
    </SidebarGroup>
</template>
