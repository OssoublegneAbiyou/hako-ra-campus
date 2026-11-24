type IconName = 'home' | 'planning' | 'tasks' | 'opportunities' | 'profile' | 'grades' | 'star' | 'bell' | 'plus' | 'close' | 'check' | 'scholarship' | 'competition' | 'internship' | 'training' | 'link';
const paths: Record<IconName, string> = {
    home: 'm3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM9 22V12h6v10',
    planning: 'M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2ZM16 2v4M8 2v4M3 10h18',
    tasks: 'm9 11 3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
    opportunities: 'M12 15a6 6 0 1 0 0-12 6 6 0 0 0 0 12ZM8.2 13.9 7 22l5-3 5 3-1.2-8.1',
    profile: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
    grades: 'M18 20V10M12 20V4M6 20v-6',
    star: 'M12 3v18M3 12h18M6 6l12 12M18 6 6 18',
    bell: 'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0',
    plus: 'M5 12h14M12 5v14',
    close: 'M6 6l12 12M18 6 6 18',
    check: 'M5 12.5 10 17 19 7',
    scholarship: 'M22 10 12 5 2 10l10 5 10-5ZM6 12v5c3 2 9 2 12 0v-5',
    competition: 'M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4ZM7 6H4a3 3 0 0 0 3 4M17 6h3a3 3 0 0 1-3 4',
    internship: 'M4 8h16v11H4zM9 8V5h6v3M4 13h16',
    training: 'M2 4h7a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H2zM22 4h-7a3 3 0 0 0-3 3v13a2 2 0 0 1 2-2h8z',
    link: 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1',
};

export function Icon({ name, size = 20, strokeWidth = 2 }: { name: string; size?: number; strokeWidth?: number }) {
    return <svg aria-hidden="true" focusable="false" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={strokeWidth} strokeLinecap="round" strokeLinejoin="round"><path d={paths[name as IconName] ?? paths.home} /></svg>;
}
