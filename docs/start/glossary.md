---
title: قاموس المصطلحات
description: مرجع سريع للمصطلحات المستخدمة في شاشات منصة المطاعم والتقارير التحليلية والإدارة.
---

# قاموس المصطلحات

يوضح هذا القاموس معنى كل مصطلح يظهر في شاشات التقارير والإدارة في منصة المطاعم.

<script setup>
import { data } from './glossary.data'
</script>

<table>
  <thead>
    <tr>
      <th>المصطلح</th>
      <th>المعنى</th>
    </tr>
  </thead>
  <tbody>
    <tr v-for="entry in data.entries" :key="entry.term">
      <td><strong>{{ entry.term }}</strong></td>
      <td>{{ entry.meaning }}</td>
    </tr>
  </tbody>
</table>
