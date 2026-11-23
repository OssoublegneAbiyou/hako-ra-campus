import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

const pages = {
    Schedule: () => import('./pages/Schedule'),
    ScheduleHistory: () => import('./pages/ScheduleHistory'),
    SchedulePublication: () => import('./pages/SchedulePublication'),
    ScheduleImport: () => import('./pages/ScheduleImport'),
    CheckIn: () => import('./pages/CheckIn'),
    CheckIns: () => import('./pages/CheckIns'),
    Tasks: () => import('./pages/Tasks'),
    Homework: () => import('./pages/Homework'),
    Grades: () => import('./pages/Grades'),
    Gradebook: () => import('./pages/Gradebook'),
    GradeCoefficients: () => import('./pages/GradeCoefficients'),
    Notifications: () => import('./pages/Notifications'),
    AttachmentReports: () => import('./pages/AttachmentReports'),
    NotificationSettings: () => import('./pages/NotificationSettings'),
    AcademicReferences: () => import('./pages/AcademicReferences'),
    Home: () => import('./pages/Home'),
    Help: () => import('./pages/Help'),
    Privacy: () => import('./pages/Privacy'),
    PrivacySettings: () => import('./pages/PrivacySettings'),
    SecondFactor: () => import('./pages/SecondFactor'),
    AccountDeletion: () => import('./pages/AccountDeletion'),
    'admin/PrivacyRequests': () => import('./pages/admin/PrivacyRequests'),
    Error: () => import('./pages/Error'),
    ComponentLab: () => import('./pages/ComponentLab'),
    Profile: () => import('./pages/Profile'),
    InvitationShow: () => import('./pages/InvitationShow'),
    'admin/Institution': () => import('./pages/admin/Institution'),
    'admin/Platform': () => import('./pages/admin/Platform'),
    'admin/Catalogue': () => import('./pages/admin/Catalogue'),
    'admin/CatalogueEdit': () => import('./pages/admin/CatalogueEdit'),
    'admin/CatalogueReports': () => import('./pages/admin/CatalogueReports'),
    Opportunities: () => import('./pages/Opportunities'),
    Opportunity: () => import('./pages/Opportunity'),
    OpportunityProfile: () => import('./pages/OpportunityProfile'),
    OpportunityFollowUp: () => import('./pages/OpportunityFollowUp'),
    Offline: () => import('./pages/Offline'),
    OpportunityAlerts: () => import('./pages/OpportunityAlerts'),
    OpportunityAlert: () => import('./pages/OpportunityAlert'),
    'auth/Login': () => import('./pages/auth/Login'),
    'auth/Register': () => import('./pages/auth/Register'),
    'auth/ForgotPassword': () => import('./pages/auth/ForgotPassword'),
    'auth/GoogleRegistration': () => import('./pages/auth/GoogleRegistration'),
    'auth/ResetPassword': () => import('./pages/auth/ResetPassword'),
    'auth/SecondFactorChallenge': () => import('./pages/auth/SecondFactorChallenge'),
    'auth/VerifyEmail': () => import('./pages/auth/VerifyEmail'),
    'auth/ConfirmPassword': () => import('./pages/auth/ConfirmPassword'),
    'auth/Activate': () => import('./pages/auth/Activate'),
    'institution/ProvisionedAccounts': () => import('./pages/institution/ProvisionedAccounts'),
};

void createInertiaApp({
    title: (title) => title ? `${title} · HAKO-RA Campus` : 'HAKO-RA Campus',
    resolve: async (name) => {
        if (!(name in pages)) throw new Error('Page indisponible.');
        const page = await pages[name as keyof typeof pages]();
        return page.default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#1f6555' },
});
