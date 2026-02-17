/**
 * モーダルクウィンドウ用
 *
 */
// NOTE: document.documentElement は常に存在するため、外部JS依存の変数は持たない
/**
 * モーダルクローズ
 *
 */
function closeModal() {
  let blockModal = document.getElementById('modalBlock');
  if (!blockModal) return;
  blockModal.classList.remove('is-active');
  blockModal.classList.remove('bg-orange');
  blockModal.classList.remove('bg-black');
  document.documentElement.style.overflow = '';
}
/**
 * モーダルクローズしてページ移動
 *
 */
function closeModalToPage(page) {
  let blockModal = document.getElementById('modalBlock');
  if (!blockModal) return;
  blockModal.classList.remove('is-active');
  blockModal.classList.remove('bg-orange');
  blockModal.classList.remove('bg-black');
  document.documentElement.style.overflow = '';
  //ページ移動
  location.href = page;
}
/**
 * HTML側（inline onclick）から呼べるようにグローバルへ公開
 *
 */
window.closeModal = closeModal;
window.closeModalToPage = closeModalToPage;
