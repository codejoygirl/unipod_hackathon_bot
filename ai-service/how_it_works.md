# How the AI Service Works (Simple Terms)

The `ai-service` acts as a highly intelligent, secure "Brain" for your community platform. It allows users to ask questions in plain English (or other languages) and get accurate answers based **only** on the documents and knowledge uploaded to your platform. 

It prevents the AI from "hallucinating" (making things up) and ensures that users from Community A can never see documents from Community B.

Here is a simple, step-by-step breakdown of how it works under the hood.

---

## 1. Learning (The Ingestion Process)

Before the AI can answer questions, it has to "read" your documents.

1. **Upload**: Your main application sends a document (like a PDF text, an announcement, or clinic hours) to the AI Service.
2. **Chopping and Parsing**: For text, the AI chops the document into smaller pieces called "Chunks". For audio or video, it transcribes speech into text segments. For images, it reads text (OCR) and describes the visual scene.
3. **Embedding**: The AI reads each chunk and converts the meaning of the text into a massive list of numbers (called a Vector or Embedding). 
4. **Storage**: It stores these chunks and numbers in a highly specialized PostgreSQL database, locking them securely to a specific `tenant_id` and `community_id`.

## 2. Searching (The Hybrid Retrieval Process)

When a user asks a question like *"When are pediatric vaccinations available?"*:

1. **Security First**: The AI service checks the user's `tenant_id`. It puts up a brick wall so it only searches through documents that belong to this specific user's community.
2. **Double Search**: 
   * **Vector Search**: It converts the user's question into numbers and finds the chunks that have the most similar *meaning*.
   * **Keyword Search**: At the same time, it looks for exact keyword matches (like "vaccinations").
3. **Combining**: It combines the best results from both searches to get a top-tier list of the most relevant paragraphs.

## 3. Pluggable Brain (OpenAI & Gemini)

The AI service doesn't rely on just one provider. It acts as a "Model Factory" that can swap out its brain depending on what you configure in the `.env` file:
*   **Google Gemini**: Super fast, handles massive context windows natively, and excels at native multimodal reasoning (Gemini 3.5 Flash & 3.1 Pro).
*   **OpenAI**: Uses GPT-4o and Text-Embedding-3 for top-tier reasoning and industry-standard vector performance.

## 4. Answering (The Generation Process)

Now that the AI has the most relevant paragraphs, it needs to form an answer.

1. **Strict Instructions**: The AI is given the paragraphs and told: *"Answer the user's question using ONLY these paragraphs. If the answer is not in these paragraphs, say you don't know."*
2. **Citing its Sources**: The AI doesn't just write an answer. It places citation markers (like `[E1]`) in the text, pointing to exactly which paragraph it used.

## 5. Double Checking (The Verification Process)

Before sending the answer back to the user, the AI Service acts like a strict teacher grading a test.

1. **Quote Checking**: It verifies that the AI's answer actually matches the source document.
2. **Conflict Detection**: If two different documents say two different things (e.g., one says vaccines are on Monday, another says Wednesday), the AI service flags it as a `CONFLICT`.
3. **Grading**: It assigns a final grade (`state`):
   * 🟢 **VERIFIED**: Perfect. High confidence, official source.
   * 🟡 **POSSIBLE**: Good, but it came from a community discussion rather than an official policy.
   * 🟠 **CONFLICT**: Documents disagree. Needs a human to look at it.
   * 🔴 **INSUFFICIENT EVIDENCE**: The AI didn't know the answer, successfully preventing a lie.

The final verified answer is then sent back to your main application to show to the user!
