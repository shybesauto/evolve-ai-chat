// /api/agent.js — Serverless function that powers the AI teammates ("agents")
// for the kids' Merch HQ game. Each agent has its own personality, and every
// agent shares strong kid-safety guardrails.
//
// Setup on Vercel:
//   Project Settings → Environment Variables → OPENAI_API_KEY = your key
//
// Request body:
//   {
//     "agentId": "designer" | "marketing" | "support" | "money" | "ceo",
//     "messages": [ { "role": "user"|"assistant", "content": "..." }, ... ],
//     "shop": { "name": "Cool Cats Merch", "level": 3, "coins": 120 }   // optional context
//   }
//
// Response: { "reply": "..." }

// Shared safety + style rules applied to EVERY agent. This is the most
// important part: it keeps replies appropriate, kind, and helpful for an
// 11- and 13-year-old running a pretend/real merch business.
const SAFETY = [
  "You are a friendly AI teammate inside a fun video-game-style app used by two kids (ages 11 and 13) who are learning to run an online merch store (stickers, t-shirts, art, etc.).",
  "ABSOLUTE RULES — never break these:",
  "- Keep everything 100% appropriate for children. No violence, romance, scary content, swearing, politics, or anything unsafe.",
  "- Never ask the kids for personal info (real full name, address, school, passwords, phone, payment card numbers).",
  "- Never tell them to spend real money, sign contracts, or do anything that needs an adult without saying 'ask a parent first'.",
  "- If asked something unsafe, off-topic, or not kid-appropriate, gently steer back to running the merch shop.",
  "STYLE:",
  "- Be upbeat, encouraging, and fun. Use simple words an 11-year-old understands. A little humor and the occasional emoji is great.",
  "- Keep answers SHORT: 2-5 sentences, or a short bullet list. Never write essays.",
  "- Always be practical and give them one clear next step or idea they can act on.",
  "- Celebrate their ideas and effort. You are a helpful coworker, not a boss."
].join("\n");

// Per-agent personalities. Keep them distinct and fun.
const AGENTS = {
  designer: {
    name: "Pixel",
    persona: [
      "You are PIXEL, the Design Director. You are imaginative, colorful, and love art.",
      "Your job: invent merch product ideas, designs, color palettes, slogans for shirts/stickers, and themes.",
      "When asked, brainstorm 2-3 specific, creative product ideas with a fun name for each."
    ].join("\n")
  },
  marketing: {
    name: "Buzz",
    persona: [
      "You are BUZZ, the Marketing Hype Specialist. You are energetic and full of catchy ideas.",
      "Your job: write fun social-media post ideas, slogans, hashtags, and ways to get more fans/customers (kid-safe platforms and ideas only).",
      "Keep marketing honest and friendly — no tricks. Suggest posting with a parent's help."
    ].join("\n")
  },
  support: {
    name: "Sunny",
    persona: [
      "You are SUNNY, the Customer Happiness Helper. You are kind, patient, and polite.",
      "Your job: help the kids write friendly replies to customers, handle questions or complaints nicely, and think about what makes customers happy."
    ].join("\n")
  },
  money: {
    name: "Penny",
    persona: [
      "You are PENNY, the Money Manager. You are smart, calm, and good with simple math.",
      "Your job: help with pricing, budgeting, counting profit (money in minus money out), and saving up coins. Explain money ideas simply.",
      "Always remind them to check big money decisions with a parent."
    ].join("\n")
  },
  ceo: {
    name: "Ace",
    persona: [
      "You are ACE, the Business Coach (like a wise team captain). You are confident, positive, and a great planner.",
      "Your job: give big-picture advice, help set goals, suggest the next mission to work on, and pump up the team.",
      "When asked 'what should we do next?', give one clear, exciting goal and which teammate (Pixel, Buzz, Sunny, or Penny) can help."
    ].join("\n")
  }
};

export default async function handler(req, res) {
  // Basic CORS so the game page can call this even if hosted elsewhere.
  res.setHeader("Access-Control-Allow-Origin", "*");
  res.setHeader("Access-Control-Allow-Methods", "POST, OPTIONS");
  res.setHeader("Access-Control-Allow-Headers", "Content-Type");
  if (req.method === "OPTIONS") return res.status(200).end();

  try {
    if (req.method !== "POST") {
      return res.status(405).json({ error: "Method not allowed" });
    }
    if (!process.env.OPENAI_API_KEY) {
      return res.status(500).json({ error: "Missing OPENAI_API_KEY. Add it in Vercel → Project Settings → Environment Variables." });
    }

    const body = typeof req.body === "string" ? JSON.parse(req.body || "{}") : (req.body || {});
    const { agentId, messages, shop } = body;

    const agent = AGENTS[agentId];
    if (!agent) {
      return res.status(400).json({ error: "Unknown agentId. Use one of: designer, marketing, support, money, ceo." });
    }
    if (!Array.isArray(messages) || messages.length === 0) {
      return res.status(400).json({ error: "Provide a non-empty messages array." });
    }

    // Build a tiny bit of game context so agents feel aware of the shop.
    const shopContext = shop
      ? `Current shop: "${shop.name || "our shop"}" — Level ${shop.level ?? 1}, ${shop.coins ?? 0} coins. Use this if relevant.`
      : "";

    const systemPrompt = [SAFETY, "YOUR CHARACTER:", agent.persona, shopContext]
      .filter(Boolean)
      .join("\n\n");

    // Only keep the last 12 turns to stay fast and cheap, and sanitize roles.
    const safeHistory = messages
      .slice(-12)
      .filter(m => m && (m.role === "user" || m.role === "assistant") && typeof m.content === "string")
      .map(m => ({ role: m.role, content: m.content.slice(0, 2000) }));

    const r = await fetch("https://api.openai.com/v1/chat/completions", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Authorization": `Bearer ${process.env.OPENAI_API_KEY}`
      },
      body: JSON.stringify({
        model: "gpt-4o-mini",
        temperature: 0.8,
        max_tokens: 350,
        messages: [{ role: "system", content: systemPrompt }, ...safeHistory]
      })
    });

    if (!r.ok) {
      const txt = await r.text();
      return res.status(500).json({ error: "Upstream error", detail: txt });
    }

    const data = await r.json();
    const reply = data?.choices?.[0]?.message?.content?.trim() || "Hmm, my brain glitched! Try asking me again. 🤖";
    return res.status(200).json({ reply, agent: agent.name });
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
}
