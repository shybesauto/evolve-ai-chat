// /api/chat.js - Vercel serverless function
export default async function handler(req, res) {
  try {
    if (req.method !== 'POST') return res.status(405).json({ error: 'Method not allowed' });
    const { message } = req.body || {};
    if (!message) return res.status(400).json({ error: 'No message' });

    const sysPrompt = [
      "You are Evolve Auto Service’s assistant. Be concise, accurate, upbeat, and proactive.",
      "Shop Info:",
      "- Name: Evolve Auto Service",
      "- Phone: (517) 669-2204",
      "- Text: (517) 893-7675",
      "- Address: 11399 Old US 27, Dewitt, MI 48820",
      "- Hours: Mon-Thu 8:00 AM–5:00 PM; Fri 8:00 AM–3:00 PM",
      "- Services: Auto repair, diagnostics, brakes, steering, engines & cooling, electrical, HVAC, tires & alignments.",
      "- ADAS: OEM procedures, static/dynamic calibrations, pre/post scans, alignment pre-checks, ride-height verification, documentation.",
      "Policies: No blind quotes—diagnose first. Ask for VIN when needed. For emergencies, advise calling the shop.",
      "Scheduling: Offer the Request Work form (ShopMonkey). Provide call/text buttons when appropriate.",
      "Tone: Professional, helpful, and clear. If unsure, ask a brief clarifying question."
    ].join("\n");

    const r = await fetch("https://api.openai.com/v1/chat/completions", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Authorization": `Bearer ${process.env.OPENAI_API_KEY}`
      },
      body: JSON.stringify({
        model: "gpt-4o-mini",
        temperature: 0.2,
        max_tokens: 400,
        messages: [
          { role: "system", content: sysPrompt },
          { role: "user", content: message }
        ]
      })
    });

    if (!r.ok) {
      const txt = await r.text();
      return res.status(500).json({ error: "Upstream error", detail: txt });
    }
    const data = await r.json();
    const reply = data?.choices?.[0]?.message?.content?.trim() || "";
    res.status(200).json({ reply });
  } catch (e) {
    res.status(500).json({ error: e.message });
  }
}
