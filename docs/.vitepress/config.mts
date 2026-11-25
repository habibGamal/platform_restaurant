import { defineConfig, type DefaultTheme } from 'vitepress'
import { withMermaid } from 'vitepress-plugin-mermaid'
import { productSwitcher } from './theme/products'

const isPublic = process.env.DOCS_PUBLIC === '1'

const productUrls = {
  pos: process.env.DOCS_POS_URL,
  website: process.env.DOCS_WEBSITE_URL,
  platform: process.env.DOCS_PLATFORM_URL,
}

const arabicSidebar: DefaultTheme.Sidebar = [
  {
    text: 'البداية',
    items: [
      { text: 'نظرة عامة', link: '/start/' },
      { text: 'تسجيل الدخول واللوحات', link: '/start/first-login' },
      { text: 'قاموس المصطلحات', link: '/start/glossary' },
    ],
  },
  {
    text: 'دليل الاستخدام',
    items: [
      { text: 'نظرة عامة على الدليل', link: '/guide/' },
      { text: 'الفلاتر والفترات والمقارنة', link: '/guide/filters-and-periods' },
      { text: 'لوحة التحكم الرئيسية', link: '/guide/overview' },
      { text: 'مقارنة المبيعات', link: '/guide/sales-comparison' },
      { text: 'الأرباح والهوامش', link: '/guide/profitability' },
      { text: 'تكلفة الخامات والهالك', link: '/guide/food-cost-and-waste' },
      { text: 'أداء الأصناف', link: '/guide/product-performance' },
      { text: 'الورديات وأيام الأسبوع', link: '/guide/shifts-and-weekdays' },
      { text: 'ترتيب الفروع', link: '/guide/branch-ranking' },
      { text: 'المستهدف مقابل المحقق', link: '/guide/targets-vs-actual' },
      { text: 'المصروفات والمشتريات', link: '/guide/expenses-and-purchases' },
      { text: 'حالة البيانات والاتصال', link: '/guide/data-health' },
      { text: 'المساعد الذكي', link: '/guide/ai-analyst' },
    ],
  },
  {
    text: 'الإدارة',
    items: [
      { text: 'المنظمات والمطاعم ومجموعات الفروع', link: '/admin/organizations' },
      { text: 'المستخدمون والصلاحيات', link: '/admin/users-and-access' },
      { text: 'إدارة الفروع ورموز الربط', link: '/admin/branches' },
      { text: 'تحديد الأهداف وأحداث التقويم', link: '/admin/targets' },
      { text: 'معايير التقييم وتصنيفات المصروفات', link: '/admin/scoring-and-expenses' },
      { text: 'إدارة القائمة وإعدادات المساعد الذكي', link: '/admin/menu-and-ai' },
    ],
  },
  {
    text: 'التكامل',
    items: [
      { text: 'ربط نقطة البيع بالمنصة', link: '/integrations/pos' },
    ],
  },
]

const devSidebar: DefaultTheme.Sidebar = [
  {
    text: 'Overview',
    items: [
      { text: 'Overview & Stack', link: '/dev/' },
      { text: 'Architecture', link: '/dev/architecture' },
      { text: 'Data Model', link: '/dev/data-model' },
    ],
  },
  {
    text: 'Ingestion & Analytics',
    items: [
      { text: 'POS Integration Contract', link: '/dev/integrations/pos' },
      { text: 'Warehouse & Aggregates', link: '/dev/warehouse-and-aggregates' },
      { text: 'Metrics & KPIs', link: '/dev/metrics-and-kpis' },
      { text: 'Reports Catalog', link: '/dev/reports' },
      { text: 'Targets & Ranking', link: '/dev/targets-and-ranking' },
      { text: 'Tenancy & Access Control', link: '/dev/tenancy-and-access' },
    ],
  },
  {
    text: 'AI & Menu Intelligence',
    items: [
      { text: 'AI Analyst & Menu Intelligence', link: '/dev/ai-analyst' },
    ],
  },
  {
    text: 'Engineering',
    items: [
      { text: 'Configuration', link: '/dev/configuration' },
      { text: 'Development Guide', link: '/dev/development' },
      { text: 'Testing', link: '/dev/testing' },
      { text: 'Documentation style guide', link: '/dev/style-guide' },
    ],
  },
  {
    text: 'Operations',
    items: [
      { text: 'Deployment', link: '/dev/ops/deployment' },
      { text: 'Operations & Troubleshooting', link: '/dev/ops/operations' },
      { text: 'Runbooks', link: '/dev/ops/runbooks' },
      { text: 'Changelog', link: '/changelog' },
    ],
  },
]

const redirects: Record<string, string> = {}

const rootNav: DefaultTheme.NavItem[] = [
  { text: 'البداية', link: '/start/', activeMatch: '^/start/' },
  { text: 'دليل الاستخدام', link: '/guide/', activeMatch: '^/guide/' },
  { text: 'الإدارة', link: '/admin/organizations', activeMatch: '^/admin/' },
  { text: 'التكامل', link: '/integrations/pos', activeMatch: '^/integrations/' },
  ...(isPublic ? [] : [{ text: 'Developer', link: '/dev/', activeMatch: '^/dev/' }]),
  productSwitcher('platform', productUrls),
]

export default withMermaid(
  defineConfig({
    title: 'منصة المطاعم',
    description: 'دليل استخدام منصة المطاعم المركزية لإدارة الفروع والتقارير التحليلية والمحلل الذكي',
    cleanUrls: true,
    lastUpdated: true,
    srcExclude: isPublic ? ['dev/**'] : [],
    head: [
      ['link', { rel: 'icon', type: 'image/svg+xml', href: '/logo.svg' }],
      ['style', {}, ':root { --brand: #4f46e5; }'],
      ['link', { rel: 'preconnect', href: 'https://fonts.googleapis.com' }],
      ['link', { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' }],
      [
        'link',
        {
          rel: 'stylesheet',
          href: 'https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap',
        },
      ],
    ],
    themeConfig: {
      logo: '/logo.svg',
      isPublic,
      redirects,
      search: {
        provider: 'local',
        options: {
          locales: {
            root: {
              translations: {
                button: { buttonText: 'بحث', buttonAriaLabel: 'بحث' },
                modal: {
                  noResultsText: 'لا توجد نتائج لـ',
                  resetButtonTitle: 'مسح',
                  footer: { selectText: 'اختيار', navigateText: 'تنقل', closeText: 'إغلاق' },
                },
              },
            },
          },
        },
      },
      socialLinks: [{ icon: 'github', link: 'https://github.com/habibGamal/platform_restaurant' }],
    } as DefaultTheme.Config & { isPublic: boolean; redirects: Record<string, string> },
    locales: {
      root: {
        label: 'العربية',
        lang: 'ar',
        dir: 'rtl',
        themeConfig: {
          nav: rootNav,
          sidebar: arabicSidebar,
          outline: { level: [2, 3], label: 'في هذه الصفحة' },
          docFooter: { prev: 'السابق', next: 'التالي' },
          lastUpdated: { text: 'آخر تحديث' },
          darkModeSwitchLabel: 'المظهر',
          lightModeSwitchTitle: 'الوضع الفاتح',
          darkModeSwitchTitle: 'الوضع الداكن',
          sidebarMenuLabel: 'القائمة',
          returnToTopLabel: 'العودة للأعلى',
          langMenuLabel: 'اللغة',
          notFound: { title: 'الصفحة غير موجودة', linkText: 'العودة للرئيسية', quote: '' },
          footer: { message: 'دليل استخدام منصة المطاعم' },
        },
      },
      ...(isPublic
        ? {}
        : {
            dev: {
              label: 'Developer (English)',
              lang: 'en',
              dir: 'ltr',
              link: '/dev/',
              themeConfig: {
                nav: [
                  { text: 'Handbook', link: '/dev/', activeMatch: '^/dev/(?!ops/)' },
                  { text: 'Operations', link: '/dev/ops/deployment', activeMatch: '^/dev/ops/' },
                  { text: 'POS Contract', link: '/dev/integrations/pos' },
                  { text: 'Style guide', link: '/dev/style-guide' },
                  { text: 'دليل المستخدم', link: '/' },
                  productSwitcher('platform', productUrls),
                ],
                sidebar: { '/dev/': devSidebar },
                outline: { level: [2, 3], label: 'On this page' },
                docFooter: { prev: 'Previous', next: 'Next' },
                lastUpdated: { text: 'Last updated' },
                footer: {
                  message: 'Platform Restaurants developer handbook. End-user Arabic guide lives at /.',
                },
              },
            },
          }),
    },
    mermaid: {
      theme: 'base',
      themeVariables: {
        primaryColor: '#e0e7ff',
        primaryBorderColor: '#4f46e5',
        primaryTextColor: '#1e1b4b',
        lineColor: '#6366f1',
        secondaryColor: '#fef3c7',
        tertiaryColor: '#f8fafc',
        clusterBkg: '#eef2ff',
        clusterBorder: '#c7d2fe',
        fontFamily: 'Inter, IBM Plex Sans Arabic, sans-serif',
      },
    },
    vite: {
      css: { postcss: {} },
      build: { chunkSizeWarningLimit: 4000 },
    },
  }),
)
