import pytest
from ai_service.providers.mock import MockLanguageDetector


@pytest.mark.asyncio
async def test_mock_detector_french_bonjour_and_hackathon():
    detector = MockLanguageDetector()
    assert await detector.detect("Bonjour\n\nY a-t-il un hackathon ?") == "fr"
    assert await detector.detect("bonjour") == "fr"
    assert await detector.detect("do you speak french") == "en"
    assert await detector.detect("Parlez-vous français ?") == "fr"


@pytest.mark.asyncio
async def test_mock_detector_english_and_spanish():
    detector = MockLanguageDetector()
    assert await detector.detect("When is the clinic open?") == "en"
    assert await detector.detect("¿Dónde está el centro médico?") == "es"
