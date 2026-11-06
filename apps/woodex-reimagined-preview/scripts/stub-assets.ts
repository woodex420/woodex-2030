import { plugin } from "bun";
import { resolve } from "node:path";

plugin({ name: "stub", setup(b) {
  b.onResolve({ filter: /^@\// }, (a) => ({ path: resolve("src", a.path.slice(2)) }));
  b.onLoad({ filter: /\.(jpg|png|jpeg|webp|svg)$/ }, () => ({ contents: "export default ''", loader: "js" }));
}});
