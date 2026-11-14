<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Bot,
    Boxes,
    ChartLine,
    CreditCard,
    LayoutGrid,
    LifeBuoy,
    MessageSquare,
    Package,
    Receipt,
    Server,
    ServerCog,
    Search,
    ShoppingCart,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { chat, dashboard } from '@/routes';
import admin from '@/routes/admin';
import customer from '@/routes/customer';
import katalog from '@/routes/katalog';
import layanan from '@/routes/layanan';
import tagihan from '@/routes/tagihan';
import tiket from '@/routes/tiket';
import type { NavItem } from '@/types';

const page = usePage();
const isAdmin = computed(() => Boolean(page.props.auth.user?.is_admin));

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: customer.dashboard(), icon: LayoutGrid },
    {
        title: 'Pesan Layanan',
        href: katalog.index(),
        icon: ShoppingCart,
        items: [
            { title: 'App 1-Klik', href: katalog.index('app') },
            { title: 'Cloud Server', href: katalog.index('cloud') },
            { title: 'Managed Server', href: katalog.index('managed-cloud') },
            { title: 'Token AI', href: katalog.index('ai') },
        ],
    },
    { title: 'Layanan Saya', href: layanan.index(), icon: Server },
    { title: 'Chat AI', href: chat(), icon: MessageSquare },
    { title: 'Tagihan', href: tagihan.index(), icon: Receipt },
    { title: 'Support Ticket', href: tiket.index(), icon: LifeBuoy },
];

const adminNavItems = computed<NavItem[]>(() => [
    { title: 'Dashboard Admin', href: dashboard(), icon: LayoutGrid },
    { title: 'Tagihan', href: admin.tagihan.index(), icon: CreditCard },
    { title: 'Pembayaran', href: admin.pembayaran.index(), icon: Wallet },
    { title: 'Tiket', href: admin.tiket.index(), icon: LifeBuoy, badge: page.props.tiketTerbuka },
    { title: 'Layanan', href: admin.layanan.index(), icon: Package },
    {
        title: 'Paket',
        href: admin.paket.index('vps'),
        icon: Boxes,
        items: [
            { title: 'Cloud Server', href: admin.paket.index('vps') },
            { title: 'Managed Server', href: admin.paket.index('managed') },
            { title: 'App', href: admin.paket.index('app') },
            { title: 'Token AI', href: admin.paket.index('ai') },
        ],
    },
    { title: 'Node', href: admin.node.index(), icon: ServerCog },
    { title: 'Pengguna', href: admin.pengguna.index(), icon: Users },
    { title: 'Analitik', href: admin.analitik.index(), icon: ChartLine },
    { title: 'SEO', href: admin.seo.index(), icon: Search },
    { title: 'Agent', href: admin.agen.index(), icon: Bot },
]);

</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <!-- admin hanya melihat menu Admin -->
            <NavMain v-if="!isAdmin" :items="mainNavItems" label="Akun" />
            <NavMain v-if="isAdmin" :items="adminNavItems" label="Admin" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
