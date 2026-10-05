import { defineTool, ToolError } from "@lovable.dev/mcp-js";
import { z } from "zod";
import data from "../data.json";
const services = data.services as any[];
const blogPosts = data.blogPosts as any[];

export const listServices = defineTool({
  name: "list_services",
  title: "List services",
  description: "List WOODEX services (custom design, B2B fitouts, delivery, etc.) with their process steps.",
  inputSchema: {},
  annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
  handler: () => {
    const list = services.map((s) => ({
      slug: s.slug,
      title: s.title,
      process: s.process.map((p) => ({ step: p.step, title: p.title, description: p.description })),
    }));
    return { content: [{ type: "text", text: JSON.stringify(list) }], structuredContent: { services: list } };
  },
});

export const listBlogPosts = defineTool({
  name: "list_blog_posts",
  title: "List blog posts",
  description: "List WOODEX blog articles with id, title, excerpt and date.",
  inputSchema: {},
  annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
  handler: () => {
    const list = blogPosts.map((p) => ({ id: p.id, title: p.title, excerpt: p.excerpt, category: p.category, date: p.date }));
    return { content: [{ type: "text", text: JSON.stringify(list) }], structuredContent: { posts: list } };
  },
});

export const getBlogPost = defineTool({
  name: "get_blog_post",
  title: "Read blog post",
  description: "Read the full text of a WOODEX blog article by id.",
  inputSchema: { id: z.string().min(1).describe("Blog post id.") },
  annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
  handler: ({ id }) => {
    const p = blogPosts.find((x) => x.id === id);
    if (!p) throw new ToolError(`No blog post with id "${id}"`);
    const text = `# ${p.title}\n\n` + p.content.map((c) => (c.heading ? `## ${c.heading}\n\n` : "") + c.body).join("\n\n");
    return { content: [{ type: "text", text }] };
  },
});
