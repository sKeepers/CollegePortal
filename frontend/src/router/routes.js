import AuthLayout from '../layouts/AuthLayout.vue'
import AppLayout from '../layouts/AppLayout.vue'
import PublicLayout from '../layouts/PublicLayout.vue'
import MobileStudentLayout from '../layouts/MobileStudentLayout.vue'
import LoginPage from '../pages/auth/LoginPage.vue'
import DashboardPage from '../pages/dashboard/DashboardPage.vue'
import StudentsPage from '../pages/students/StudentsPage.vue'
import GroupsPage from '../pages/groups/GroupsPage.vue'
import TeachersPage from '../pages/teachers/TeachersPage.vue'
import SubjectsPage from '../pages/subjects/SubjectsPage.vue'
import ClassroomsPage from '../pages/classrooms/ClassroomsPage.vue'
import CurriculaPage from '../pages/curricula/CurriculaPage.vue'
import SchedulePage from '../pages/schedule/SchedulePage.vue'
import JournalPage from '../pages/journal/JournalPage.vue'
import TeachingLoadPage from '../pages/teaching-load/TeachingLoadPage.vue'
import ExamsPage from '../pages/exams/ExamsPage.vue'
import GraduationPage from '../pages/graduation/GraduationPage.vue'
import FrdoPage from '../pages/frdo/FrdoPage.vue'
import FisPage from '../pages/fis/FisPage.vue'
import ReportsPage from '../pages/reports/ReportsPage.vue'
import AdmissionsPage from '../pages/admissions/AdmissionsPage.vue'
import DigitalPassesPage from '../pages/identity/DigitalPassesPage.vue'
import AccessGatePage from '../pages/access/AccessGatePage.vue'
import AccessReportsPage from '../pages/access/AccessReportsPage.vue'
import ApplicantPublicPage from '../pages/public/ApplicantPublicPage.vue'
import UiFoundationPage from '../pages/system/UiFoundationPage.vue'
import DataManagementPage from '../pages/admin/DataManagementPage.vue'
import UniversalImportPage from '../pages/admin/UniversalImportPage.vue'
import UsersPage from '../pages/admin/users/UsersPage.vue'
import RolesPage from '../pages/admin/roles/RolesPage.vue'
import AuditPage from '../pages/admin/audit/AuditPage.vue'
import SettingsPage from '../pages/admin/settings/SettingsPage.vue'
import ReferenceDataPage from '../pages/admin/reference/ReferenceDataPage.vue'
import MobileStudentHomePage from '../pages/mobile/student/MobileStudentHomePage.vue'
import MobileStudentPassPage from '../pages/mobile/student/MobileStudentPassPage.vue'

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
    path: '/m/student',
    component: MobileStudentLayout,
    children: [
      {
        path: '',
        name: 'mobile-student-home',
        component: MobileStudentHomePage,
        meta: { title: 'Кабинет студента' },
      },
      {
        path: 'pass',
        name: 'mobile-student-pass',
        component: MobileStudentPassPage,
        meta: { title: 'QR-пропуск студента' },
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
        path: 'curricula',
        name: 'curricula',
        component: CurriculaPage,
        meta: { title: 'Учебные планы', permission: 'manage_dictionaries' },
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
        path: 'teaching-load',
        name: 'teaching-load',
        component: TeachingLoadPage,
        meta: { title: 'Нагрузка преподавателей', permission: 'manage_dictionaries' },
      },
      {
        path: 'exams',
        name: 'exams',
        component: ExamsPage,
        meta: { title: 'Экзамены и ГИА', permission: 'manage_dictionaries' },
      },
      {
        path: 'graduation',
        name: 'graduation',
        component: GraduationPage,
        meta: { title: 'Выпускники и дипломы', permission: 'manage_dictionaries' },
      },
      {
        path: 'frdo',
        name: 'frdo',
        component: FrdoPage,
        meta: { title: 'ФРДО', permission: 'manage_dictionaries' },
      },
      {
        path: 'fis',
        name: 'fis',
        component: FisPage,
        meta: { title: 'ФИС', permission: 'manage_dictionaries' },
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
        path: 'access/gate',
        name: 'access-gate',
        component: AccessGatePage,
        meta: { title: 'Проходная', permission: 'manage_dictionaries' },
      },
      {
        path: 'access/reports',
        name: 'access-reports',
        component: AccessReportsPage,
        meta: { title: 'Отчеты по проходам', permission: 'manage_dictionaries' },
      },
      {
        path: 'identity/digital-passes',
        name: 'identity-digital-passes',
        component: DigitalPassesPage,
        meta: { title: 'Цифровые пропуска', permission: 'manage_dictionaries' },
      },
      {
        path: 'admin/audit',
        name: 'admin-audit',
        component: AuditPage,
        meta: { title: 'Аудит', permission: 'manage_users' },
      },
      {
        path: 'admin/settings',
        name: 'admin-settings',
        component: SettingsPage,
        meta: { title: 'Настройки колледжа', permission: 'manage_users' },
      },
      {
        path: 'admin/reference',
        name: 'admin-reference',
        component: ReferenceDataPage,
        meta: { title: 'Справочники', permission: 'manage_dictionaries' },
      },
      {
        path: 'admin/roles',
        name: 'admin-roles',
        component: RolesPage,
        meta: { title: 'Роли', permission: 'manage_users' },
      },
      {
        path: 'admin/users',
        name: 'admin-users',
        component: UsersPage,
        meta: { title: 'Пользователи', permission: 'manage_users' },
      },
      {
        path: 'admin/import',
        name: 'admin-import',
        component: UniversalImportPage,
        meta: { title: 'Импорт данных', permission: 'manage_dictionaries' },
      },
      {
        path: 'admin/data-management',
        name: 'admin-data-management',
        component: DataManagementPage,
        meta: { title: 'Управление данными', permission: 'manage_dictionaries' },
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
