import { describe, expect, it } from "vitest";

import { ANSWER_STATES, ANSWER_STATE_VALUES, answerPresentation, isAnswerState } from "../answer-state";

describe("answer states", () => {
  it("covers every value the API can return, including INSUFFICIENT_EVIDENCE", () => {
    expect(ANSWER_STATE_VALUES).toEqual([
      "VERIFIED",
      "POSSIBLE",
      "CONFLICT",
      "INSUFFICIENT_EVIDENCE",
      "UNKNOWN",
      "BLOCKED",
    ]);
  });

  it("gives each state its own label, so colour is never the only signal", () => {
    const labels = ANSWER_STATE_VALUES.map((state) => ANSWER_STATES[state].label);
    expect(new Set(labels).size).toBe(labels.length);
  });

  it("escalates exactly the states the backend enum does", () => {
    const escalating = ANSWER_STATE_VALUES.filter((state) => ANSWER_STATES[state].escalates);
    expect(escalating).toEqual(["CONFLICT", "INSUFFICIENT_EVIDENCE", "UNKNOWN"]);
  });

  it("gives every state a description to fall back on when the answer has no prose", () => {
    for (const state of ANSWER_STATE_VALUES) {
      expect(ANSWER_STATES[state].description.length).toBeGreaterThan(0);
    }
  });

  it("rejects lowercase and unknown values, which is how a stale response would arrive", () => {
    expect(isAnswerState("verified")).toBe(false);
    expect(isAnswerState("SUPERSEDED")).toBe(false);
    expect(isAnswerState("VERIFIED")).toBe(true);
  });

  it("resolves a presentation by state", () => {
    expect(answerPresentation("BLOCKED").tone).toBe("clay");
    expect(answerPresentation("VERIFIED").tone).toBe("accent");
  });
});
