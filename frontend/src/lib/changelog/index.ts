import entries from "./app-changelog.json";

export type ChangelogTag = "Feature" | "Improvement" | "Fix" | "UI";

export type ChangelogEntry = {
  version: string;
  date: string;
  isLatest?: boolean;
  title: string;
  changes: {
    tag: ChangelogTag;
    description: string;
  }[];
};

export const CHANGELOG_ENTRIES = entries as ChangelogEntry[];

export const LATEST_CHANGELOG =
  CHANGELOG_ENTRIES.find((entry) => entry.isLatest) ?? CHANGELOG_ENTRIES[0];
