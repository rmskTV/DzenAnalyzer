// MV3 service worker: открывает дашборд по клику на иконку
// и по сообщению из контент-скрипта (навигация от расширения —
// не блокируется ни web_accessible-ограничениями, ни адблокерами).
chrome.action.onClicked.addListener(() => {
  chrome.tabs.create({ url: chrome.runtime.getURL('dashboard.html') })
})

chrome.runtime.onMessage.addListener((msg) => {
  if (msg?.act === 'open-dashboard') {
    chrome.tabs.create({ url: chrome.runtime.getURL('dashboard.html') })
  }
})
