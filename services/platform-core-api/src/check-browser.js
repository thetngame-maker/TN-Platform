import { chromium } from "playwright";
try { const executable=chromium.executablePath(); const browser=await chromium.launch({headless:true,args:["--disable-dev-shm-usage","--no-sandbox"]}); await browser.close(); console.log(JSON.stringify({ok:true,browser:"chromium",executable})); } catch(error) { console.error(JSON.stringify({ok:false,error:error.message})); process.exit(1); }
