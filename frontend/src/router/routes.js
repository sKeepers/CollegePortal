import AuthLayout from '../layouts/AuthLayout.vue'
import AppLayout from '../layouts/AppLayout.vue'
import PublicLayout from '../layouts/PublicLayout.vue'
import LoginPage from '../pages/auth/LoginPage.vue'
import DashboardPage from '../pages/dashboard/DashboardPage.vue'
import StudentsPage from '../pages/students/StudentsPage.vue'
import GroupsPage from '../pages/groups/GroupsPage.vue'
import TeachersPage from '../pages/teachers/TeachersPage.vue'
import SubjectsPage from '../pages/subjects/SubjectsPage.vue'
import ClassroomsPage from '../pages/classrooms/ClassroomsPage.vue'
import SchedulePage from '../pages/schedule/SchedulePage.vue'
import JournalPage from '../pages/journal/JournalPage.vue'
import ReportsPage from '../pages/reports/ReportsPage.vue'
import AdmissionsPage from '../pages/admissions/AdmissionsPage.vue'
import DigitalPassesPage from '../pages/identity/DigitalPassesPage.vue'
import ApplicantPublicPage from '../pages/public/ApplicantPublicPage.vue'
import UiFoundationPage from '../pages/system/UiFoundationPage.vue'

export const routes = [
  {
    path: '/login',
    component: AuthLayout,
    meta: { public: true },
    children: [
      {
        path: '',
        name: 'login',
        component: LoginPage,
        meta: { title: 'Вход' },
      },
    ],
  },
  {
    path: '/public',
    component: PublicLayout,
    meta: { public: true },
    children: [
      {
        path: 'applicant',
        name: 'public-applicant',
        component: ApplicantPublicPage,
        meta: { title: 'Абитуриенту' },
      },
    ],
  },
  {
    path: '/legacy',
    name: 'legacy',
    component: () => import('../pages/legacy/LegacyPage.vue'),
    meta: { public: true, title: 'Старый интерфейс' },
  },
  {
    path: '/',
    component: AppLayout,
    children: [
      {
        path: '',
        redirect: '/dashboard',
      },
      {
        path: 'dashboard',
        name: 'dashboard',
        component: DashboardPage,
        meta: { title: 'Панель' },
      },
      {
        path: 'students',
        name: 'students',
        component: StudentsPage,
        meta: { title: 'Студенты', permission: 'manage_dictionaries' },
      },
      {
        path: 'groups',
        name: 'groups',
        component: GroupsPage,
        meta: { title: 'Группы', permission: 'manage_dictionaries' },
      },
      {
        path: 'teachers',
        name: 'teachers',
        component: TeachersPage,
        meta: { title: 'Преподаватели', permission: 'manage_dictionaries' },
      },
      {
        path: 'subjects',
        name: 'subjects',
        component: SubjectsPage,
        meta: { title: 'Дисциплины', permission: 'manage_dictionaries' },
      },
      {
        path: 'classrooms',
        name: 'classrooms',
        component: ClassroomsPage,
        meta: { title: 'Аудитории', permission: 'manage_dictionaries' },
      },
      {
        path: 'schedule',
        name: 'schedule',
        component: SchedulePage,
        meta: { title: 'Расписание', permission: 'manage_schedule' },
      },
      {
        path: 'journal',
        name: 'journal',
        component: JournalPage,
        meta: { title: 'Журнал', permission: 'manage_journal' },
      },
      {
        path: 'reports',
        name: 'reports',
        component: ReportsPage,
        meta: { title: 'Отчеты', permission: 'manage_journal' },
      },
      {
        path: 'admissions',
        name: 'admissions',
        component: AdmissionsPage,
        meta: { title: 'Приемная комиссия', permission: 'manage_dictionaries' },
      },
      {
        path: 'identity/digital-passes',
        name: 'identity-digital-passes',
        component: DigitalPassesPage,
        meta: { title: 'Цифровые пропуска', permission: 'manage_dictionaries' },
      },
      {
        path: 'system/ui-foundation',
        name: 'system-ui-foundation',
        component: UiFoundationPage,
        meta: { title: 'UI Foundation', adminOnly: true },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/dashboard',
  },
]
