import { defineConfig } from 'vitepress'
import { withMermaid } from 'vitepress-plugin-mermaid'

const arStartSidebar = [
  {
    text: 'البداية',
    items: [
      { text: 'ما هي المنصة وربط الفروع', link: '/start/' },
    ],
  },
]

const arGuideSidebar = [
  {
    text: 'دليل المستخدم',
    items: [
      { text: 'نظرة عامة على الدليل', link: '/guide/' },
    ],
  },
]

const integrationsSidebar = [
  {
    text: 'Integrations',
    items: [
      { text: 'POS -> Platform Contract', link: '/integrations/pos' },
    ],
  },
]

const devSidebar = [
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
      { text: 'POS Integration Contract', link: '/integrations/pos' },
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

export default withMermaid(
  defineConfig({
    title: 'منصة المطاعم',
    description: 'Central reporting, multi-unit analytics, POS ingestion, and AI analyst for restaurant chains',
    cleanUrls: true,
    lastUpdated: true,
    head: [
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
    },
    locales: {
      root: {
        label: 'العربية',
        lang: 'ar',
        dir: 'rtl',
        themeConfig: {
          nav: [
            { text: 'البداية', link: '/start/', activeMatch: '/start/' },
            { text: 'دليل المستخدم', link: '/guide/', activeMatch: '/guide/' },
            { text: 'عقد POS', link: '/integrations/pos' },
            { text: 'Developer Docs', link: '/dev/' },
            { text: 'سجل التغييرات', link: '/changelog' },
          ],
          sidebar: {
            '/start/': arStartSidebar,
            '/guide/': arGuideSidebar,
            '/integrations/': integrationsSidebar,
          },
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
          footer: { message: 'وثائق منصة المطاعم' },
        },
      },
      dev: {
        label: 'English (Developer)',
        lang: 'en',
        dir: 'ltr',
        link: '/dev/',
        themeConfig: {
          nav: [
            { text: 'Overview', link: '/dev/', activeMatch: '/dev/' },
            { text: 'POS Contract', link: '/integrations/pos' },
            { text: 'Reports', link: '/dev/reports' },
            { text: 'Operations', link: '/dev/ops/deployment' },
            { text: 'العربية', link: '/' },
          ],
          sidebar: { '/dev/': devSidebar },
          outline: { level: [2, 3] },
          footer: {
            message: 'Platform Restaurants developer documentation.',
          },
        },
      },
    },
    mermaid: {
      theme: 'base',
      themeVariables: {
        primaryColor: '#ccfbf1',
        primaryBorderColor: '#0f766e',
        primaryTextColor: '#134e4a',
        lineColor: '#0d9488',
        secondaryColor: '#fef3c7',
        tertiaryColor: '#f8fafc',
        clusterBkg: '#f0fdfa',
        clusterBorder: '#99f6e4',
        fontFamily: 'Inter, IBM Plex Sans Arabic, sans-serif',
      },
    },
  }),
)
