<template>
  <div class="container">
    <div class="head">
      <h1>Дзен-аналитика</h1>
      <div class="tabs">
        <button :class="{ active: tab === 'channels' }" @click="tab = 'channels'">Каналы</button>
        <button :class="{ active: tab === 'analysis' }" @click="switchToAnalysis()">Анализ</button>
      </div>
    </div>

    <Channels v-if="tab === 'channels'" @updated="dataVersion++" @analysis="switchToAnalysis()" />
    <Analysis v-else :key="dataVersion" />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import Channels from './pages/Channels.vue'
import Analysis from './pages/Analysis.vue'

const tab = ref('channels')
const dataVersion = ref(0)

function switchToAnalysis() {
  dataVersion.value++
  tab.value = 'analysis'
}
</script>
