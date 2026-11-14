<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    { label: 'Menu' },
);

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>{{ label }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="
                        isCurrentOrParentUrl(item.href) ||
                        !!item.items?.some((s) => isCurrentOrParentUrl(s.href))
                    "
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuBadge
                    v-if="item.badge"
                    class="rounded-full bg-amber-500 px-1.5 text-white peer-hover/menu-button:text-white peer-data-[active=true]/menu-button:text-white"
                    :aria-label="`${item.badge} belum dijawab`"
                    >{{ item.badge > 99 ? '99+' : item.badge }}</SidebarMenuBadge
                >
                <SidebarMenuSub v-if="item.items?.length">
                    <SidebarMenuSubItem v-for="sub in item.items" :key="sub.title">
                        <SidebarMenuSubButton
                            as-child
                            :is-active="isCurrentOrParentUrl(sub.href)"
                        >
                            <Link :href="sub.href">
                                <span>{{ sub.title }}</span>
                            </Link>
                        </SidebarMenuSubButton>
                    </SidebarMenuSubItem>
                </SidebarMenuSub>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
