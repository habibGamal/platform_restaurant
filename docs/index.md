---
layout: home
title: منصة المطاعم
description: مركز التقارير التحليلية وتجميع بيانات الفروع والمحلل الذكي لسلاسل المطاعم.

hero:
  name: منصة المطاعم
  text: مركز التقارير التحليلية وتجميع بيانات الفروع
  tagline: منصة مركزية لسلاسل المطاعم تجمع بيانات نقاط البيع وتقدّم لوحات متابعة، مؤشرات أداء، أهدافاً شهرية، ومحللاً ذكياً باللغة العربية.
  image:
    src: /logo.svg
    alt: منصة المطاعم
  actions:
    - theme: brand
      text: ابدأ من هنا
      link: /start/
    - theme: alt
      text: دليل الاستخدام
      link: /guide/
    - theme: alt
      text: الإدارة
      link: /admin/organizations
---

<script setup>
import { computed } from 'vue'
import { useData } from 'vitepress'
import { VPFeatures } from 'vitepress/theme'

const { theme } = useData()

const allSections = [
  {
    title: 'البداية',
    details: 'نظرة عامة على المنصة، تسجيل الدخول واللوحات حسب الدور، وقاموس المصطلحات.',
    link: '/start/',
  },
  {
    title: 'دليل الاستخدام',
    details: 'الفلاتر والفترات، لوحة التحكم الرئيسية، التقارير العشرة التحليلية، والمساعد الذكي.',
    link: '/guide/',
  },
  {
    title: 'الإدارة',
    details: 'المنظمات والمطاعم، المستخدمون والصلاحيات، الفروع ورموز الربط، الأهداف، معايير التقييم، وإعدادات المساعد.',
    link: '/admin/organizations',
  },
  {
    title: 'التكامل',
    details: 'ربط نقطة البيع بالمنصة عبر رمز الربط، وما يتزامن من بيانات، ومتابعة حالة الاتصال.',
    link: '/integrations/pos',
  },
  {
    title: 'Developer',
    details: 'Architecture, data model, POS ingestion contract, KPI formulas, AI analyst, and testing.',
    link: '/dev/',
    privateOnly: true,
  },
  {
    title: 'Deployment and operations',
    details: 'Runtime queues, scheduled jobs, production deployment, operations, and runbooks.',
    link: '/dev/ops/deployment',
    privateOnly: true,
  },
]

const visibleSections = computed(() =>
  allSections.filter((section) => !theme.value.isPublic || !section.privateOnly),
)
</script>

<VPFeatures :features="visibleSections" />

<div v-if="!theme.isPublic" style="margin-top: 2rem; text-align: center;">
  <p>للمطورين وفريق الدعم الفني: انتقل إلى <a href="/dev/">وثائق المطورين (Developer handbook)</a>.</p>
</div>
