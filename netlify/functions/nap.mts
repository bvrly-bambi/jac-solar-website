const DEFAULT_BACKEND = 'https://script.google.com/macros/s/AKfycbxYhMaOtVlqx8MHU-sJsC0TnwH6I2cDENVW5AMOvCjOiUsTj8UHJsAqIHdEiyYmIGDtSA/exec';
const ALLOWED = new Set(['getBootstrap','getTeamJobs','validateTicket','startOrResumeSession','submitNapUpdate','finishSession']);

function json(status: number, body: unknown) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' }
  });
}

async function proxy(action: string, payload: Record<string, unknown>) {
  const response = await fetch(DEFAULT_BACKEND, {
    method: 'POST',
    headers: { 'Content-Type': 'text/plain;charset=utf-8' },
    body: JSON.stringify({ action, payload }),
    redirect: 'follow'
  });
  const text = await response.text();
  let parsed: any;
  try { parsed = JSON.parse(text); }
  catch { throw new Error('Update server returned an invalid response.'); }
  if (!response.ok) throw new Error(parsed?.error || `Update server error (${response.status}).`);
  return parsed;
}

export default async (req: Request) => {
  try {
    if (req.method === 'GET') {
      const url = new URL(req.url);
      if (url.searchParams.get('health') !== '1') return json(405, { ok:false, error:'Method not allowed.' });
      const data = await proxy('getBootstrap', {});
      return json(200, { ok:true, health:'NAP backend reachable', backendOk:data?.ok !== false });
    }
    if (req.method !== 'POST') return json(405, { ok:false, error:'Method not allowed.' });
    const body = await req.json().catch(() => ({} as any));
    const action = String((body as any)?.action || '');
    const payload = (body as any)?.payload && typeof (body as any).payload === 'object' ? (body as any).payload : {};
    if (!ALLOWED.has(action)) return json(400, { ok:false, error:'Unsupported action.' });
    const data = await proxy(action, payload);
    return json(200, data);
  } catch (err: any) {
    console.error('NAP proxy error', err);
    return json(502, { ok:false, error:err?.message || 'Update server error.' });
  }
};

export const config = { path: '/api/nap' };