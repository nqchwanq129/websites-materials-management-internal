const helpSearch = document.getElementById('help-search');
const helpTopics = [...document.querySelectorAll('[data-help-topic]')];
const helpFaqs = [...document.querySelectorAll('[data-help-faq]')];
const helpFaq = document.getElementById('help-faq');
let helpFaqState;
helpSearch.addEventListener('input', () => {
    const query = normalize(helpSearch.value.trim());
    const words = query.split(/[^\p{L}\p{N}]+/u).filter(Boolean);
    if (query && !helpFaqState) helpFaqState = helpFaqs.map(item => item.open);
    for (const item of [...helpTopics, ...helpFaqs]) {
        const textWords = normalize(item.textContent).split(/[^\p{L}\p{N}]+/u);
        item.hidden = !words.every(word => textWords.some(textWord => textWord.startsWith(word)));
    }
    helpFaqs.forEach((item, index) => { item.open = query ? !item.hidden : (helpFaqState?.[index] ?? item.open); });
    if (!query) helpFaqState = null;
    helpFaq.hidden = helpFaqs.every(item => item.hidden);
    document.querySelectorAll('[data-help-index]').forEach(link => { link.hidden = document.getElementById(link.dataset.helpIndex).hidden; });
    const count = [...helpTopics, ...helpFaqs].filter(item => !item.hidden).length;
    const status = document.getElementById('help-search-status');
    status.hidden = !query; status.textContent = `Tìm thấy ${count} nội dung phù hợp.`;
    document.getElementById('help-empty').hidden = count !== 0;
});
// Deep links expand the matching FAQ/topic even when opened from outside this page.
function revealHelpAnchor() {
    const target = document.getElementById(location.hash.slice(1));
    if (target?.matches('[data-help-topic],#help-faq')) {
        helpSearch.value = ''; helpSearch.dispatchEvent(new Event('input'));
        target.scrollIntoView({ block: 'start' });
    }
}
window.addEventListener('hashchange', revealHelpAnchor);
revealHelpAnchor();
