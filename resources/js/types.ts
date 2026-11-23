export type NavigationItem = {
    key: string;
    label: string;
    href: string | null;
    pending: boolean;
};

export type OneTimeSecret = { name: string; identifier: string; secret: string };
export type OneTimeLink = { url: string; role: string; email: string | null; expiresAt: string };

export type CampusProps = {
    [key: string]: unknown;
    auth: { user: { name: string } | null; accountKey: string | null };
    flash: { status: string | null; problem: string | null; oneTimeSecret: OneTimeSecret | null; oneTimeLink: OneTimeLink | null };
    campus: {
        development: boolean;
        version: string;
        context: { kind: 'individual' | 'institution' | 'platform'; label: string; description: string; membershipId: number | null };
        contexts: { membershipId: number; label: string }[];
        navigation: NavigationItem[];
        unreadNotifications: number | null;
        privacyNoticePending: boolean;
        administration: { label: string; href: string }[];
    };
};
