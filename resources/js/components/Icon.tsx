type IconName = 'home' | 'planning' | 'tasks' | 'opportunities' | 'profile' | 'grades';
const paths: Record<IconName, string> = {
    home: 'M3 10 12 3l9 7v11h-6v-7H9v7H3Z',
    planning: 'M4 5h16v16H4ZM4 10h16M8 3v4m8-4v4M8 14h2m4 0h2m-8 3h2',
    tasks: 'M9 6h12M9 12h12M9 18h12M3 5l1 1 2-2M3 11l1 1 2-2M3 17l1 1 2-2',
    opportunities: 'm12 3 3 6 6 3-6 3-3 6-3-6-6-3 6-3Z',
    profile: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-2a8 8 0 0 1 16 0v2',
    grades: 'M5 3h14v18H5ZM8 8h8M8 12h8M8 16h4',
};

export function Icon({ name }: { name: string }) {
    return <svg aria-hidden="true" focusable="false" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round"><path d={paths[name as IconName] ?? paths.home} /></svg>;
}
