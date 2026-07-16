import AuthLayout from '../layouts/AuthLayout.vue'
import AppLayout from '../layouts/AppLayout.vue'
import PublicLayout from '../layouts/PublicLayout.vue'
import MobileStudentLayout from '../layouts/MobileStudentLayout.vue'
const LoginPage = () => import('../pages/auth/LoginPage.vue')
const DashboardPage = () => import('../pages/dashboard/DashboardPage.vue')
const PeoplePage = () => import('../pages/people/PeoplePage.vue')
const StudentsPage = () => import('../pages/students/StudentsPage.vue')
const GroupsPage = () => import('../pages/groups/GroupsPage.vue')
const TeachersPage = () => import('../pages/teachers/TeachersPage.vue')
const SubjectsPage = () => import('../pages/subjects/SubjectsPage.vue')
const ClassroomsPage = () => import('../pages/classrooms/ClassroomsPage.vue')
const CurriculaPage = () => import('../pages/curricula/CurriculaPage.vue')
const SchedulePage = () => import('../pages/schedule/SchedulePage.vue')
const JournalPage = () => import('../pages/journal/JournalPage.vue')
const TeachingLoadPage = () => import('../pages/teaching-load/TeachingLoadPage.vue')
const ExamsPage = () => import('../pages/exams/ExamsPage.vue')
const GraduationPage = () => import('../pages/graduation/GraduationPage.vue')
const FrdoPage = () => import('../pages/frdo/FrdoPage.vue')
const FisPage = () => import('../pages/fis/FisPage.vue')
const ReportsPage = () => import('../pages/reports/ReportsPage.vue')
const AttendancePage = () => import('../pages/attendance/AttendancePage.vue')
const AdmissionsPage = () => import('../pages/admissions/AdmissionsPage.vue')
const DigitalPassesPage = () => import('../pages/identity/DigitalPassesPage.vue')
const AccessPassPage = () => import('../pages/access/AccessPassPage.vue')
const AccessGatePage = () => import('../pages/access/AccessGatePage.vue')
const MobileScannerPage = () => import('../pages/access/MobileScannerPage.vue')
const AccessReportsPage = () => import('../pages/access/AccessReportsPage.vue')
const ScannerTestPage = () => import('../pages/access/ScannerTestPage.vue')
const ApplicantPublicPage = () => import('../pages/public/ApplicantPublicPage.vue')
const UiFoundationPage = () => import('../pages/system/UiFoundationPage.vue')
const ForbiddenPage = () => import('../pages/system/ForbiddenPage.vue')
const DataManagementPage = () => import('../pages/admin/DataManagementPage.vue')
const UniversalImportPage = () => import('../pages/admin/UniversalImportPage.vue')
const UsersPage = () => import('../pages/admin/users/UsersPage.vue')
const RolesPage = () => import('../pages/admin/roles/RolesPage.vue')
const PermissionsPage = () => import('../pages/admin/permissions/PermissionsPage.vue')
const AuditPage = () => import('../pages/admin/audit/AuditPage.vue')
const SettingsPage = () => import('../pages/admin/settings/SettingsPage.vue')
const ReferenceDataPage = () => import('../pages/admin/reference/ReferenceDataPage.vue')
const UatPage = () => import('../pages/admin/uat/UatPage.vue')
const HrEmployeesPage = () => import('../pages/hr/HrEmployeesPage.vue')
const HrCalendarPage = () => import('../pages/hr/HrCalendarPage.vue')
const MobileStudentHomePage = () => import('../pages/mobile/student/MobileStudentHomePage.vue')
const MobileStudentPassPage = () => import('../pages/mobile/student/MobileStudentPassPage.vue')

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
    meta: { permission: 'mobile.student.view' },
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
        meta: { title: 'QR-пропуск студента', permission: 'mobile.student.pass' },
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
    path: '/forbidden',
    name: 'forbidden',
    component: ForbiddenPage,
    meta: { title: 'Недостаточно прав' },
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
        path: 'people',
        name: 'people',
        component: PeoplePage,
        meta: { title: 'Люди / Person', permission: 'people.view' },
      },
      {
        path: 'students',
        name: 'students',
        component: StudentsPage,
        meta: { title: 'Студенты', permission: 'students.view' },
      },
      {
        path: 'groups',
        name: 'groups',
        component: GroupsPage,
        meta: { title: 'Группы', permission: 'groups.view' },
      },
      {
        path: 'teachers',
        name: 'teachers',
        component: TeachersPage,
        meta: { title: 'Преподаватели', permission: 'teachers.view' },
      },
      {
        path: 'subjects',
        name: 'subjects',
        component: SubjectsPage,
        meta: { title: 'Дисциплины', permission: 'subjects.view' },
      },
      {
        path: 'curricula',
        name: 'curricula',
        component: CurriculaPage,
        meta: { title: 'Учебные планы', permission: 'curricula.view' },
      },
      {
        path: 'classrooms',
        name: 'classrooms',
        component: ClassroomsPage,
        meta: { title: 'Аудитории', permission: 'classrooms.view' },
      },
      {
        path: 'schedule',
        name: 'schedule',
        component: SchedulePage,
        meta: { title: 'Расписание', permission: 'schedule.view' },
      },
      {
        path: 'journal',
        name: 'journal',
        component: JournalPage,
        meta: { title: 'Журнал', permission: 'journal.view' },
      },
      {
        path: 'teaching-load',
        name: 'teaching-load',
        component: TeachingLoadPage,
        meta: { title: 'Нагрузка преподавателей', permission: 'teachingload.view' },
      },
      {
        path: 'exams',
        name: 'exams',
        component: ExamsPage,
        meta: { title: 'Экзамены и ГИА', permission: 'exams.view' },
      },
      {
        path: 'graduation',
        name: 'graduation',
        component: GraduationPage,
        meta: { title: 'Выпускники и дипломы', permission: 'graduation.view' },
      },
      {
        path: 'frdo',
        name: 'frdo',
        component: FrdoPage,
        meta: { title: 'ФРДО', permission: 'frdo.view' },
      },
      {
        path: 'fis',
        name: 'fis',
        component: FisPage,
        meta: { title: 'ФИС', permission: 'fis.view' },
      },
      {
        path: 'reports',
        name: 'reports',
        component: ReportsPage,
        meta: { title: 'Отчеты', permission: 'journal.view' },
      },
      {
        path: 'attendance',
        name: 'attendance',
        component: AttendancePage,
        meta: { title: 'Посещаемость', permission: 'attendance.reports' },
      },
      {
        path: 'admissions',
        name: 'admissions',
        component: AdmissionsPage,
        meta: { title: 'Приемная комиссия', permission: 'admissions.view' },
      },
      {
        path: 'hr/employees',
        name: 'hr-employees',
        component: HrEmployeesPage,
        meta: { title: 'Сотрудники', permission: 'hr.employees.view' },
      },
      {
        path: 'hr/calendar',
        name: 'hr-calendar',
        component: HrCalendarPage,
        meta: { title: 'Кадровый календарь', permission: 'hr.calendar.view' },
      },
      {
        path: 'hr/departments',
        name: 'hr-departments',
        component: HrEmployeesPage,
        meta: { title: 'Подразделения', permission: 'hr.departments.manage' },
      },
      {
        path: 'hr/positions',
        name: 'hr-positions',
        component: HrEmployeesPage,
        meta: { title: 'Должности', permission: 'hr.positions.manage' },
      },
      {
        path: 'access/pass',
        name: 'access-pass',
        component: AccessPassPage,
        meta: { title: 'Мой QR-пропуск' },
      },
      {
        path: 'access/checkpoint',
        name: 'access-checkpoint',
        component: AccessGatePage,
        meta: { title: 'Проходная', permission: 'access.scan' },
      },
      {
        path: 'access/gate',
        name: 'access-gate',
        component: AccessGatePage,
        meta: { title: 'Проходная', permission: 'access.scan' },
      },
      {
        path: 'access/mobile-scanner',
        name: 'access-mobile-scanner',
        component: MobileScannerPage,
        meta: { title: 'Мобильный сканер', permission: 'access.scan' },
      },
      {
        path: 'access/reports',
        name: 'access-reports',
        component: AccessReportsPage,
        meta: { title: 'Отчеты по проходам', permission: 'access.reports' },
      },
      {
        path: 'access/scanner-test',
        name: 'access-scanner-test',
        component: ScannerTestPage,
        meta: { title: 'Тест QR-сканера', adminOnly: true, permission: 'access.scan' },
      },
      {
        path: 'identity/digital-passes',
        name: 'identity-digital-passes',
        component: DigitalPassesPage,
        meta: { title: 'Цифровые пропуска', permission: 'digitalpasses.manage' },
      },
      {
        path: 'admin/audit',
        name: 'admin-audit',
        component: AuditPage,
        meta: { title: 'Аудит', permission: 'audit.view' },
      },
      {
        path: 'admin/settings',
        name: 'admin-settings',
        component: SettingsPage,
        meta: { title: 'Настройки колледжа', permission: 'settings.manage' },
      },
      {
        path: 'admin/reference',
        name: 'admin-reference',
        component: ReferenceDataPage,
        meta: { title: 'Справочники', permission: 'reference.manage' },
      },
      {
        path: 'admin/roles',
        name: 'admin-roles',
        component: RolesPage,
        meta: { title: 'Роли', permission: 'roles.manage' },
      },
      {
        path: 'admin/permissions',
        name: 'admin-permissions',
        component: PermissionsPage,
        meta: { title: 'Разрешения', permission: 'permissions.manage' },
      },
      {
        path: 'admin/users',
        name: 'admin-users',
        component: UsersPage,
        meta: { title: 'Пользователи', permission: 'users.manage' },
      },
      {
        path: 'admin/import',
        name: 'admin-import',
        component: UniversalImportPage,
        meta: { title: 'Импорт данных', permission: 'import.manage' },
      },
      {
        path: 'admin/data-management',
        name: 'admin-data-management',
        component: DataManagementPage,
        meta: { title: 'Управление данными', permission: 'import.manage' },
      },
      {
        path: 'admin/uat',
        name: 'admin-uat',
        component: UatPage,
        meta: { title: 'UAT', permission: 'uat.manage' },
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
