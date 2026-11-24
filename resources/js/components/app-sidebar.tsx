import { Link } from '@inertiajs/react';
import {
    FolderGit2,
    GraduationCap,
    Heart,
    House,
    ShieldAlert,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { usePage } from '@inertiajs/react';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { rejected } from '@/routes/admin';
import { index as favorites } from '@/routes/favorites';
import { index as learn } from '@/routes/learn';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    { title: 'Home', href: dashboard(), icon: House },
    { title: 'Learn', href: learn(), icon: GraduationCap },
    { title: 'Favorites', href: favorites(), icon: Heart },
];

const adminNavItems: NavItem[] = [
    { title: 'Rejected', href: rejected(), icon: ShieldAlert },
];

const footerNavItems: NavItem[] = [
    {
        title: 'GitHub',
        href: 'https://github.com/Bestora/holo-learn',
        icon: FolderGit2,
    },
];

export function AppSidebar() {
    const isAdmin = usePage().props.auth.user?.is_admin === true;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain
                    items={
                        isAdmin
                            ? [...mainNavItems, ...adminNavItems]
                            : mainNavItems
                    }
                />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
