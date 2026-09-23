"""Evaluations for link-list caption quality (readable, task-shaped, not CTA paste)."""

from ai_service.evaluations.link_answer_quality import evaluate_link_answer_quality


def test_good_tiktok_caption_passes():
    answer = (
        "The UniPods TikTok is:\n\n"
        "1. UniPods TikTok (@timbuktoounipods)\n"
        "https://www.tiktok.com/@timbuktoounipods\n"
    )
    report = evaluate_link_answer_quality(
        answer,
        question="What's the UniPods TikTok?",
    )
    assert report.passed is True
    assert report.weak_caption_count == 0
    assert report.same_line_url_count == 0
    assert report.url_count == 1


def test_cta_caption_and_same_line_url_fail():
    answer = (
        "The TikTok handle shared for the UniPods platform is:\n\n"
        "1. Follow, like, repost and share our videos so we can reach even more: "
        "https://www.tiktok.com/@timbuktoounipods?r=1&_t=ZS-99cbj4oT5lc\n"
    )
    report = evaluate_link_answer_quality(answer, question="TikTok handle")
    assert report.passed is False
    assert report.same_line_url_count >= 1 or report.weak_caption_count >= 1


def test_speaker_chat_paste_caption_fails():
    answer = (
        "Here are the profiles:\n\n"
        "1. Jackson: Hello everyone, am Ssekyanzi Jackson a Software Engineer\n"
        "https://www.linkedin.com/in/ssekyanzi-jackson\n"
    )
    report = evaluate_link_answer_quality(
        answer,
        question="Give me the social media handles",
    )
    assert report.passed is False
    assert report.weak_caption_count >= 1


def test_meaningful_social_captions_pass():
    answer = (
        "Here are the social profiles shared in the community:\n\n"
        "1. Jackson (software engineer) - LinkedIn\n"
        "https://www.linkedin.com/in/ssekyanzi-jackson\n\n"
        "2. Enock Mokaya - GitHub\n"
        "https://github.com/kiongosss\n"
    )
    report = evaluate_link_answer_quality(
        answer,
        question="Give me the social media handles of the platform",
    )
    assert report.passed is True
    assert report.caption_count == 2
    assert report.url_count == 2


def test_non_link_answer_is_neutral_pass():
    report = evaluate_link_answer_quality(
        "Clinic opens Saturday at 9am.",
        question="When is clinic open?",
    )
    assert report.passed is True
    assert report.url_count == 0
