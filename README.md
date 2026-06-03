# 🚀 Merch Mogul HQ

A fun, video-game-style headquarters where kids run an **online merch shop** (stickers, t-shirts, art, and more) with the help of a team of **AI teammates**. Made for an 11- and 13-year-old, but fun for any young entrepreneur.

Tap a teammate to chat, give them jobs, complete missions, earn coins, and level up your empire! 🎮

---

## 🎮 What's inside

- **5 AI teammates**, each an expert with its own personality:
  - 🎨 **Pixel** — Design Director (product ideas, colors, slogans)
  - 📣 **Buzz** — Hype Specialist (marketing, social posts, getting customers)
  - 🌟 **Sunny** — Customer Helper (friendly replies, keeping customers happy)
  - 💰 **Penny** — Money Manager (prices, budgets, profit)
  - 🚀 **Ace** — Business Coach (big plans, goals, what to do next)
- **Chat** with any teammate to get real ideas (powered by OpenAI).
- **Assign jobs** to teammates and watch their status change.
- **Mission Board** with challenges that earn coins + XP.
- **Levels, coins, XP bar, day streaks, and confetti** 🎉
- **Idea Vault** to save your best product ideas.
- Progress **saves automatically** in the browser.

---

## 🧑‍🔧 Setup for parents (about 5 minutes)

This is a static web page plus one serverless function. The easiest way to run it is **Vercel** (free).

1. Push this repo to GitHub (already done if you're reading this there).
2. Go to [vercel.com](https://vercel.com), click **Add New → Project**, and import this repository.
3. In **Project Settings → Environment Variables**, add:
   - **`OPENAI_API_KEY`** = your OpenAI API key (from <https://platform.openai.com>)
4. Click **Deploy**.

When it finishes, you'll get a link like `https://your-project.vercel.app` — open it and play! The kids' game lives at the root, and the AI runs at `/api/agent`.

> 💡 The AI uses the affordable `gpt-4o-mini` model. You can set a monthly spending limit in your OpenAI account dashboard for peace of mind.

### Running it on your own computer (optional)
```bash
npm i -g vercel
vercel dev        # then open the printed http://localhost:3000
```
(Set `OPENAI_API_KEY` in a `.env` file or your shell first.)

---

## 🛟 Safety built in

Every AI teammate has strong, non-negotiable rules baked into the app:
- Always **kid-appropriate** — no scary, violent, or grown-up topics.
- **Never asks for private info** (real name, address, school, passwords, card numbers).
- For anything that **costs real money**, it tells the kids to **ask a parent first**.
- Stays focused on running the merch shop.

The rules live in [`api/agent.js`](api/agent.js) (see the `SAFETY` section) if you'd like to review or adjust them.

---

## 🗂️ Project structure

```
index.html      # the game page
styles.css      # the colorful game theme
app.js          # game logic (levels, missions, chat, saving)
api/agent.js    # AI teammates endpoint (OpenAI + kid-safety rules)
api/chat.js     # (pre-existing) Evolve Auto Service chat widget endpoint
```

---

## 💡 Ideas for growing the game later

- Add real product photos and a simple "store front" page.
- Connect to a kid-safe store platform with a parent's account.
- Add more teammates (a "Shipping" helper, an "Art" gallery, etc.).
- Track real sales numbers in the Shop Stats tab.

Have fun building your empire! 🏆
