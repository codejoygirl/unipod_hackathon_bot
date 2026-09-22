import os
from gtts import gTTS
from PIL import Image, ImageDraw, ImageFont

def generate_audio(text, filename):
    tts = gTTS(text=text, lang='en')
    tts.save(filename)
    print(f"Generated {filename}")

def generate_image(text, filename):
    # Create a 400x200 white image
    img = Image.new('RGB', (400, 200), color=(255, 255, 255))
    d = ImageDraw.Draw(img)
    # Simple text drawing
    d.text((20, 80), text, fill=(0, 0, 0))
    img.save(filename)
    print(f"Generated {filename}")

if __name__ == "__main__":
    text = "what is about to test"
    generate_audio(text, "test_audio.mp3")
    generate_image(text, "test_image.jpg")
