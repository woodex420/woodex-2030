// Public read-only bridge. Never forwards cookies, authorization or staff routes.
export function nativeCategoryBridge(env = process.env) {
  return async (req, res) => {
    const raw = env.NATIVE_CATALOGUE_ORIGIN;
    if (!raw || env.NATIVE_CATEGORY_SYNC === '0') return res.status(503).json({ disabled: true });
    let origin;
    try {
      origin = new URL(raw);
      const local = ['127.0.0.1', 'localhost'].includes(origin.hostname) && env.NODE_ENV !== 'production' && env.VERCEL !== '1';
      if ((!local && origin.protocol !== 'https:') || origin.username || origin.password || origin.search || origin.hash || origin.pathname !== '/') throw new Error('Invalid origin');
    } catch { return res.status(503).json({ error: 'Native category origin is not configured safely.' }); }
    const suffix = req.path.replace(/^\/api\/v1\/catalogue\/categories/, '');
    if (suffix !== '' && !/^\/\d+\/hero\/\d+$/.test(suffix)) return res.sendStatus(404);
    try {
      const response = await fetch(new URL('/api/v1/catalogue/categories' + suffix, origin), {
        headers: { Accept: suffix ? 'image/*' : 'application/json' }, redirect: 'error', signal: AbortSignal.timeout(5000),
      });
      if (!response.ok) return res.status(response.status === 404 ? 404 : 503).json({ error: 'Native catalogue unavailable.' });
      const type = response.headers.get('content-type') || '';
      if (suffix ? !/^image\/(png|jpeg|webp)(;|$)/.test(type) : !type.includes('application/json')) return res.sendStatus(502);
      const limit = suffix ? 4 * 1024 * 1024 : 2 * 1024 * 1024;
      const chunks = []; let size = 0;
      for await (const chunk of response.body) {
        size += chunk.length;
        if (size > limit) throw new Error('Native response too large');
        chunks.push(chunk);
      }
      return res.set({ 'Content-Type': type, 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' }).send(Buffer.concat(chunks));
    } catch { return res.status(503).json({ error: 'Native catalogue unavailable.' }); }
  };
}
