/**
 * TEST-ONLY entry point: the real API, plus a private channel for raw SQL.
 *
 * Spawned by scripts/lib/api-under-test.mjs. It imports apps/api/server.mjs
 * unchanged — same boot, same schema, same seed, same routes — and then listens
 * on the process IPC channel so the test that spawned it can run a statement on
 * the server's own database connection.
 *
 * Why not open the database from the test, as the suites used to? Because that
 * only works on SQLite. A PGlite directory belongs to one process at a time, so
 * a test cannot open the database its server is holding. Going through the
 * server's connection is the one approach that is the same on both engines,
 * which is what lets one test body run on both.
 *
 * Why not an HTTP route? A route that executes SQL must never exist in the
 * application, not even behind a flag. This file is not part of the
 * application: nothing imports it, no deployment starts it, and it does
 * nothing unless a parent process opened an IPC channel to it.
 *
 * The statements still bypass every rule the API enforces in JavaScript. That
 * is the point of the checks that use this: "the database itself refuses".
 */
const runtime = await import("../../apps/api/server.mjs");

if (typeof process.send === "function") {
  process.on("message", async (message) => {
    if (!message || message.kind !== "sql") return;
    const { id, mode, sql, params = [] } = message;
    try {
      let result;
      if (mode === "exec") result = (await runtime.db.exec(sql), null);
      else {
        const statement = runtime.db.prepare(sql);
        result = mode === "run" ? await statement.run(...params)
          : mode === "get" ? (await statement.get(...params)) ?? null
          : await statement.all(...params);
      }
      process.send({ kind: "sql-result", id, ok: true, result });
    } catch (error) {
      process.send({ kind: "sql-result", id, ok: false, error: String(error?.message ?? error) });
    }
  });
  process.send({ kind: "ready", driver: runtime.db.kind });
}
