import { chromium } from 'playwright';

const TERMS = ['desenvolvedor júnior','desenvolvedora júnior','programador júnior','developer junior','software engineer junior','PHP júnior','Laravel júnior','C# júnior','.NET júnior','Java júnior','Python júnior','JavaScript júnior','frontend júnior','backend júnior','full stack júnior','analista de sistemas júnior','analista de suporte júnior','suporte técnico júnior','infraestrutura júnior','QA júnior'];
const MAX_PAGES = 3, MAX_LINKS = 30;
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

async function search(page, term) {
  const url = new URL('https://candidatos.abler.com.br/vagas');
  url.searchParams.set('q', term);
  await page.goto(url.toString(), { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {});
  await page.waitForTimeout(1500);
  const found = new Set();
  for (let p = 0; p < MAX_PAGES && found.size < MAX_LINKS; p++) {
    const hrefs = await page.locator('a[href]').evaluateAll(as => as.map(a => a.href));
    for (const href of hrefs) {
      const id = href.match(/(?:abler\.in\/v|\/v)(\d+)(?:[/?#]|$)/i)?.[1];
      if (id) found.add('https://abler.in/v' + id);
      if (found.size >= MAX_LINKS) break;
    }
    if (p === MAX_PAGES - 1 || found.size >= MAX_LINKS) break;
    const next = page.locator('button[aria-label*="próxima" i],a[aria-label*="próxima" i],button[aria-label*="next" i],a[aria-label*="next" i],button:has-text("Próxima"),a:has-text("Próxima"),button:has-text("Next"),a:has-text("Next")').first();
    if (!(await next.count()) || !(await next.isVisible().catch(() => false)) || !(await next.isEnabled().catch(() => false))) break;
    await next.click();
    await page.waitForTimeout(1200);
  }
  return [...found].slice(0, MAX_LINKS);
}

async function detail(page, url) {
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  await page.waitForTimeout(700);
  const data = await page.evaluate(() => {
    const txt = el => (el?.innerText || el?.textContent || '').replace(/\s+/g, ' ').trim();
    const meta = s => document.querySelector(s)?.content || '';
    const json = [...document.querySelectorAll('script[type="application/ld+json"]')].flatMap(s => { try { const x=JSON.parse(s.textContent); return Array.isArray(x)?x:[x]; } catch { return []; } });
    const job = json.find(x => x['@type'] === 'JobPosting') || {};
    const body = txt(document.body);
    const label = name => {
      const el = [...document.querySelectorAll('label,dt,strong,b')].find(x => new RegExp('^'+name+'\\s*:?$','i').test(txt(x)));
      return txt(el?.nextElementSibling);
    };
    const addr = job.jobLocation?.address || {};
    const loc = [addr.addressLocality,addr.addressRegion,addr.addressCountry].filter(Boolean).join(' - ') || label('Localização') || label('Local');
    const desc = job.description || [...document.querySelectorAll('main,article,[class*="description" i],[class*="detail" i]')].map(txt).sort((a,b)=>b.length-a.length)[0] || meta('meta[name="description"]');
    const work = job.jobLocationType === 'TELECOMMUTE' ? 'Remoto' : (/remoto/i.test(loc+' '+body.slice(0,1000))?'Remoto':(/híbrido|hibrido/i.test(body.slice(0,1600))?'Híbrido':(/presencial/i.test(body.slice(0,1600))?'Presencial':'')));
    return {
      title: job.title || meta('meta[property="og:title"]') || txt(document.querySelector('h1')) || document.title,
      company: job.hiringOrganization?.name || label('Empresa'),
      location: loc, description: desc,
      published_at: job.datePosted || document.querySelector('time[datetime]')?.getAttribute('datetime') || '',
      employment_type: Array.isArray(job.employmentType)?job.employmentType.join(', '):(job.employmentType||''),
      salary: job.baseSalary?.value?.value ? String(job.baseSalary.value.value) : '',
      work_model: work
    };
  });
  return {...data, external_id:'abler-global:'+(url.match(/\/v(\d+)/)?.[1]||''), application_url:page.url()};
}

const browser = await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
const page = await browser.newPage({locale:'pt-BR',viewport:{width:1440,height:1000}});
const vacancies = new Map();
const diagnostics = {terms:TERMS.length,pages_per_term:MAX_PAGES,links_per_term:MAX_LINKS,links_found:0,details_opened:0,errors:[]};
try {
  for (const term of TERMS) {
    try {
      const links = await search(page,term);
      diagnostics.links_found += links.length;
      for (const link of links) {
        if (vacancies.has(link)) continue;
        try {
          vacancies.set(link,{...(await detail(page,link)),search_term:term});
          diagnostics.details_opened++;
        } catch(e) { diagnostics.errors.push({url:link,error:String(e.message||e).slice(0,200)}); }
        await sleep(200);
      }
    } catch(e) { diagnostics.errors.push({term,error:String(e.message||e).slice(0,200)}); }
  }
  process.stdout.write(JSON.stringify({vacancies:[...vacancies.values()],diagnostics}));
} finally { await browser.close(); }
