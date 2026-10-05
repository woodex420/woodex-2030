import { plugin } from "bun";
plugin({ name: "stub", setup(b) {
  b.onResolve({ filter: /^@\// }, (a) => ({ path: require("path").resolve("src", a.path.slice(2)) }));
  b.onLoad({ filter: /\.(jpg|png|jpeg|webp|svg)$/ }, () => ({ contents: "export default ''", loader: "js" }));
}});
